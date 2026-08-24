import Foundation
import NetworkExtension

actor VPNManager {
    static let shared = VPNManager(); private var manager: NETunnelProviderManager?
    func load() async { manager = (try? await NETunnelProviderManager.loadAllFromPreferences())?.first }
    func connect(location: LocationItem, premium: Bool) async throws {
        let m = manager ?? NETunnelProviderManager(); let proto = NETunnelProviderProtocol()
        proto.providerBundleIdentifier = "ir.blluepanel.bluevpn.tunnel"; proto.serverAddress = location.name
        proto.providerConfiguration = ["location_id": location.id, "subscription_url": location.subscriptionURL ?? "", "mode": premium ? "xray" : "warp", "ip_mode": "v4"]
        m.protocolConfiguration = proto; m.localizedDescription = "BlueVPN"; m.isEnabled = true
        try await m.saveToPreferences(); try await m.loadFromPreferences(); try m.connection.startVPNTunnel(); manager = m
    }
    func disconnect() { manager?.connection.stopVPNTunnel() }
    func verifyRealEgress() async throws {
        try await Task.sleep(for: .seconds(1.2)); let url = URL(string: "https://www.cloudflare.com/cdn-cgi/trace")!
        let (data, response) = try await URLSession.shared.data(from: url)
        guard (response as? HTTPURLResponse)?.statusCode == 200, String(data: data, encoding: .utf8)?.contains("ip=") == true else { throw URLError(.cannotConnectToHost) }
    }
}
