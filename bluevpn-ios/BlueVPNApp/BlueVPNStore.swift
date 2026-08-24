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
            let payload = try await APIClient.shared.get("/wp-json/bluevpn/v1/ios/bootstrap", as: ControlPlaneEnvelope.self)
            if let value = payload.locations, !value.isEmpty { locations = value; saveLocations(value) }
            campaigns = payload.campaigns ?? campaigns
        } catch { /* stale-while-revalidate: cached locations remain usable */ }
    }
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

