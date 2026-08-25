import Foundation
import NetworkExtension
import Network

final class PacketTunnelProvider: NEPacketTunnelProvider {
    private var core: BlueTunnelCore?
    override func startTunnel(options: [String : NSObject]?, completionHandler: @escaping (Error?) -> Void) {
        guard let proto = protocolConfiguration as? NETunnelProviderProtocol,
              let config = proto.providerConfiguration,
              let mode = config["mode"] as? String else { completionHandler(TunnelError.invalidConfiguration); return }
        let settings = NEPacketTunnelNetworkSettings(tunnelRemoteAddress: "162.159.197.1")
        let ipv4 = NEIPv4Settings(addresses:["172.16.0.2"],subnetMasks:["255.255.255.255"]); ipv4.includedRoutes=[.default()]; settings.ipv4Settings=ipv4
        settings.ipv6Settings=nil // Iran ISP policy: IPv4-first, no unusable IPv6 route.
        settings.mtu=1361; let dns=NEDNSSettings(servers:["1.1.1.1","1.0.0.1"]); dns.matchDomains=[""];settings.dnsSettings=dns
        setTunnelNetworkSettings(settings) { [weak self] error in
            if let error { completionHandler(error); return }
            let runtime = BlueTunnelCore(packetFlow:self?.packetFlow, mode:mode, configuration:config); self?.core=runtime
            runtime.start(completion:completionHandler)
        }
    }
    override func stopTunnel(with reason: NEProviderStopReason, completionHandler: @escaping () -> Void) { core?.stop();core=nil;completionHandler() }
}

enum TunnelError: LocalizedError { case invalidConfiguration, runtimeNotEmbedded, packetBridgeNotEmbedded
    var errorDescription:String? { switch self { case .invalidConfiguration:return "تنظیمات اتصال iOS ناقص است";case .runtimeNotEmbedded:return "هسته iOS باید در مرحله امضای نهایی Embed شود";case .packetBridgeNotEmbedded:return "Packet Bridge ممیزی‌شده iOS هنوز Embed نشده است" } }
}

/// Stable boundary for the forthcoming signed Xray/Aether XCFramework. Keeping
/// packet handling behind one adapter prevents UI/control-plane drift and lets
/// App Store builds swap the audited native runtime without touching screens.
final class BlueTunnelCore {
    private weak var packetFlow:NEPacketTunnelFlow?; private let mode:String; private let configuration:[String:Any];private let runtime=BlueXrayRuntime()
    init(packetFlow:NEPacketTunnelFlow?,mode:String,configuration:[String:Any]){self.packetFlow=packetFlow;self.mode=mode;self.configuration=configuration}
    func start(completion:@escaping(Error?)->Void){
        // Fail closed unless both the audited Xray runtime and packet bridge exist.
        guard BlueRuntimeContract.embeddedRuntimeAvailable else { completion(TunnelError.runtimeNotEmbedded); return }
        Task {
            do {
                let xrayJSON = try await resolveXrayConfiguration()
                try runtime.validate(configuration: xrayJSON)
                guard BluePacketBridge.embedded, packetFlow != nil else { throw TunnelError.packetBridgeNotEmbedded }
                try runtime.run(configuration: xrayJSON)
                completion(nil)
            } catch { runtime.stop();completion(error) }
        }
    }
    func stop(){runtime.stop()}
    private func resolveXrayConfiguration() async throws -> String {
        if let value=configuration["xray_json"] as? String,!value.isEmpty{return value}
        if let value=configuration["subscription_text"] as? String,!value.isEmpty{return try runtime.convertSubscription(value)}
        guard mode=="xray",let raw=configuration["subscription_url"] as? String,let url=URL(string:raw),url.scheme=="https" else {throw BlueXrayError.configurationUnavailable}
        let (data,response)=try await URLSession.shared.data(from:url)
        guard (response as? HTTPURLResponse)?.statusCode==200,let text=String(data:data,encoding:.utf8),!text.isEmpty else {throw BlueXrayError.configurationUnavailable}
        return try runtime.convertSubscription(text)
    }
}

enum BluePacketBridge {
    // Public NetworkExtension APIs expose NEPacketTunnelFlow, not a TUN fd.
    // This remains false until the separately audited packet-flow bridge is linked.
    static let embedded = false
}
