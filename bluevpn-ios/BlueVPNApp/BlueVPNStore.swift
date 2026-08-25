import Foundation
import SwiftUI

@MainActor final class BlueVPNStore: ObservableObject {
    @Published var path: [AppRoute] = []
    @Published var state: ConnectionState = .idle
    @Published var account = AccountSnapshot()
    @Published var locations: [LocationItem] = []
    @Published var selected: LocationItem?
    @Published var selectionMode: SelectionMode = .automatic
    @Published var campaigns: [Campaign] = []
    @Published var plans: [PlanItem] = []
    @Published var releaseChannel = "stable"
    @Published var betaTester = false
    @Published var controlPlaneError: String?
    @Published var theme: BlueVPNThemeMode = .system
    @Published var upload = "0 B/s"; @Published var download = "0 B/s"; @Published var duration = "00:00:00"
    private let vpn = VPNManager.shared
    private let pools = PoolCoordinator.shared
    private var poolSources: [PoolSource] = []

    var statusTitle: String { switch state { case .idle: return "آماده اتصال"; case .preparing: return "در حال آماده‌سازی"; case .connecting: return "در حال اتصال"; case .verifying: return "در حال بررسی اتصال"; case .connected: return "متصل و امن"; case .disconnecting: return "در حال قطع اتصال"; case .failed: return "اتصال برقرار نشد" } }
    var statusCaption: String { if case .failed(let m) = state { return m }; return state == .connected ? "خروجی اینترنت با موفقیت تأیید شد" : "بهترین اتصال به‌صورت خودکار انتخاب می‌شود" }

    func bootstrap() async {
        theme = BlueVPNThemeMode(rawValue: UserDefaults.standard.string(forKey: "theme_mode") ?? "system") ?? .system
        locations = cachedLocations()
        poolSources = cachedPoolSources()
        await refreshControlPlane()
        await vpn.load()
    }
    func refreshControlPlane() async {
        do {
            let payload = try await APIClient.shared.get("/api/v1/mobile/config", token: SecureSession.shared.token, as: MobileConfig.self)
            campaigns = payload.advertising?.enabled == true ? payload.advertising?.items ?? [] : []
            releaseChannel = payload.updatePolicy?.channel ?? payload.releaseChannel ?? "stable"
            betaTester = payload.updatePolicy?.betaTester ?? payload.betaTester ?? false
            if let sources = payload.freeAccess?.sources {
                let usable = sources.filter { $0.subscriptionURL?.isEmpty == false }
                let mappedPools = usable.map { source in
                    PoolSource(id: source.id, name: source.name, url: source.subscriptionURL!, priority: source.priority, tier: .free, countryCode: CountryDetector.code(for: source.name))
                }
                let mapped = mappedPools.map { source in
                    LocationItem(id:source.id,name:source.name,countryCode:source.countryCode,flag:Self.flag(for:source.countryCode),subscriptionURL:source.url,priority:source.priority)
                }
                if !mapped.isEmpty { poolSources=mappedPools;locations=mapped;saveLocations(mapped);savePoolSources(mappedPools) }
            }
            controlPlaneError=nil
            if SecureSession.shared.token != nil { await refreshAccount(); await refreshPlans() }
            let warmSources = connectionSources()
            if !warmSources.isEmpty { Task { await pools.warm(warmSources, token: SecureSession.shared.token) } }
        } catch { controlPlaneError=error.localizedDescription /* stale-while-revalidate */ }
    }
    func login(email:String,password:String) async throws {
        let body=LoginBody(email:email,password:password,deviceID:DeviceIdentity.shared.id,deviceName:"iPhone / BlueVPN")
        let result=try await APIClient.shared.post("/api/v1/auth/login",body:body,as:AuthEnvelope.self)
        SecureSession.shared.save(token:result.token,refreshToken:result.refreshToken);account=AccountSnapshot(result.account)
        await refreshControlPlane()
    }
    func logout() async { SecureSession.shared.clear();account=AccountSnapshot();plans=[];await refreshControlPlane() }
    func refreshAccount() async { guard let token=SecureSession.shared.token else{return};do{let result=try await APIClient.shared.get("/api/v1/account",token:token,as:AccountEnvelope.self);account=AccountSnapshot(result.account)}catch{controlPlaneError=error.localizedDescription} }
    func refreshPlans() async { guard let token=SecureSession.shared.token else{return};do{let result=try await APIClient.shared.get("/api/v1/plans",token:token,as:PlansEnvelope.self);plans=result.plans}catch{controlPlaneError=error.localizedDescription} }
    func toggle() async {
        if state == .connected { state = .disconnecting; await vpn.disconnect(); state = .idle; return }
        state = .preparing
        var sources = connectionSources()
        if selectionMode == .manual, let selected { sources = sources.filter { $0.id == selected.id || $0.url == selected.subscriptionURL } }
        guard !sources.isEmpty else { state = .failed("لوکیشن قابل استفاده دریافت نشد"); return }
        var resolvedSourceID: String?
        let started = Date()
        do {
            let cached = try await pools.resolve(sources, token: SecureSession.shared.token); resolvedSourceID = cached.source.id
            let target = locations.first(where: { $0.id == cached.source.id }) ?? LocationItem(id:cached.source.id,name:cached.source.name,countryCode:cached.source.countryCode,flag:Self.flag(for:cached.source.countryCode),subscriptionURL:cached.source.url,priority:cached.source.priority)
            state = .connecting
            try await vpn.connect(location: target, premium: cached.source.tier == .premium, subscriptionText: cached.content, sourceID: cached.source.id)
            state = .verifying; try await vpn.verifyRealEgress()
            await pools.record(sourceID: cached.source.id, success: true, latencyMS: Int(Date().timeIntervalSince(started) * 1000)); state = .connected
        } catch {
            if let resolvedSourceID { await pools.record(sourceID: resolvedSourceID, success: false) }
            await vpn.disconnect(); state = .failed(error.localizedDescription)
        }
    }
    func setTheme(_ value: BlueVPNThemeMode) { theme = value; UserDefaults.standard.set(value.rawValue, forKey: "theme_mode") }
    private func cachedLocations() -> [LocationItem] { guard let data = UserDefaults.standard.data(forKey: "locations") else { return [] }; return (try? JSONDecoder().decode([LocationItem].self, from: data)) ?? [] }
    private func saveLocations(_ value: [LocationItem]) { UserDefaults.standard.set(try? JSONEncoder().encode(value), forKey: "locations") }
    private func cachedPoolSources() -> [PoolSource] { guard let data=UserDefaults.standard.data(forKey:"pool_sources") else{return []};return (try? JSONDecoder().decode([PoolSource].self,from:data)) ?? [] }
    private func savePoolSources(_ value:[PoolSource]) { UserDefaults.standard.set(try? JSONEncoder().encode(value),forKey:"pool_sources") }
    private func connectionSources() -> [PoolSource] {
        if account.active, !account.subscriptionURL.isEmpty {
            return [PoolSource(id:account.poolIdentity.isEmpty ? "premium-account" : "premium-\(account.poolIdentity)",name:account.planTitle.isEmpty ? "Premium" : account.planTitle,url:account.subscriptionURL,priority:-100,tier:.premium,countryCode:"")]
        }
        return poolSources
    }
    private static func flag(for code:String)->String {
        guard code.count==2 else{return "🌐"};return code.uppercased().unicodeScalars.compactMap{UnicodeScalar(127397 + Int($0.value))}.map(String.init).joined()
    }
}
