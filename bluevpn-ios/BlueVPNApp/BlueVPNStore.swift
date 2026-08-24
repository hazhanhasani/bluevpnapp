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

    var statusTitle: String { switch state { case .idle: return "آماده اتصال"; case .preparing: return "در حال آماده‌سازی"; case .connecting: return "در حال اتصال"; case .verifying: return "در حال بررسی اتصال"; case .connected: return "متصل و امن"; case .disconnecting: return "در حال قطع اتصال"; case .failed: return "اتصال برقرار نشد" } }
    var statusCaption: String { if case .failed(let m) = state { return m }; return state == .connected ? "خروجی اینترنت با موفقیت تأیید شد" : "بهترین اتصال به‌صورت خودکار انتخاب می‌شود" }

    func bootstrap() async {
        theme = BlueVPNThemeMode(rawValue: UserDefaults.standard.string(forKey: "theme_mode") ?? "system") ?? .system
        locations = cachedLocations()
        await refreshControlPlane()
        await vpn.load()
    }
    func refreshControlPlane() async {
        do {
            let payload = try await APIClient.shared.get("/api/v1/mobile/config", token: SecureSession.shared.token, as: MobileConfig.self)
            campaigns = payload.advertising?.enabled == true ? payload.advertising?.items ?? [] : []
            releaseChannel = payload.updatePolicy?.channel ?? payload.releaseChannel ?? "stable"
            betaTester = payload.updatePolicy?.betaTester ?? payload.betaTester ?? false
            if locations.isEmpty, let sources = payload.freeAccess?.sources {
                let mapped = sources.filter { $0.subscriptionURL?.isEmpty == false }.map { LocationItem(id:$0.id,name:$0.name,countryCode:"",flag:"🌐",subscriptionURL:$0.subscriptionURL,priority: -$0.priority) }
                if !mapped.isEmpty { locations=mapped;saveLocations(mapped) }
            }
            controlPlaneError=nil
            if SecureSession.shared.token != nil { await refreshAccount(); await refreshPlans() }
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
        let target = selected ?? locations.sorted { $0.priority > $1.priority }.first
        guard let target else { state = .failed("لوکیشن قابل استفاده دریافت نشد"); return }
        do { state = .connecting; try await vpn.connect(location: target, premium: account.tier == .premium); state = .verifying; try await vpn.verifyRealEgress(); state = .connected }
        catch { await vpn.disconnect(); state = .failed(error.localizedDescription) }
    }
    func setTheme(_ value: BlueVPNThemeMode) { theme = value; UserDefaults.standard.set(value.rawValue, forKey: "theme_mode") }
    private func cachedLocations() -> [LocationItem] { guard let data = UserDefaults.standard.data(forKey: "locations") else { return [] }; return (try? JSONDecoder().decode([LocationItem].self, from: data)) ?? [] }
    private func saveLocations(_ value: [LocationItem]) { UserDefaults.standard.set(try? JSONEncoder().encode(value), forKey: "locations") }
}
