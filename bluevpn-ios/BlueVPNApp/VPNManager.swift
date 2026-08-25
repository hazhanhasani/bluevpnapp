import Foundation
import NetworkExtension

actor VPNManager {
    static let shared = VPNManager(); private var manager: NETunnelProviderManager?
    private var baselineIP: String?
    func load() async { manager = (try? await NETunnelProviderManager.loadAllFromPreferences())?.first }
    func connect(location: LocationItem, premium: Bool, subscriptionText: String, sourceID: String) async throws {
        baselineIP = try? await publicIP()
        let m = manager ?? NETunnelProviderManager(); let proto = NETunnelProviderProtocol()
        proto.providerBundleIdentifier = "ir.blluepanel.bluevpn.tunnel"; proto.serverAddress = location.name
        proto.providerConfiguration = [
            "location_id": location.id,
            "pool_source_id": sourceID,
            "subscription_url": location.subscriptionURL ?? "",
            "subscription_text": subscriptionText,
            "mode": "xray",
            "account_tier": premium ? "premium" : "free",
            "ip_mode": "v4"
        ]
        m.protocolConfiguration = proto; m.localizedDescription = "BlueVPN"; m.isEnabled = true
        try await m.saveToPreferences(); try await m.loadFromPreferences(); try m.connection.startVPNTunnel(); manager = m
    }
    func disconnect() { manager?.connection.stopVPNTunnel() }
    func verifyRealEgress() async throws {
        try await Task.sleep(for: .seconds(1.2))
        let current = try await publicIP()
        guard !current.isEmpty, baselineIP == nil || current != baselineIP else { throw URLError(.cannotConnectToHost) }
    }
    private func publicIP() async throws -> String {
        let url = URL(string: "https://www.cloudflare.com/cdn-cgi/trace")!
        let (data, response) = try await URLSession.shared.data(from: url)
        guard (response as? HTTPURLResponse)?.statusCode == 200,
              let trace = String(data: data, encoding: .utf8),
              let line = trace.split(separator: "\n").first(where: { $0.hasPrefix("ip=") }) else { throw URLError(.cannotConnectToHost) }
        return String(line.dropFirst(3))
    }
}
