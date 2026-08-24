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

enum TunnelError: LocalizedError { case invalidConfiguration, runtimeNotEmbedded
    var errorDescription:String? { switch self { case .invalidConfiguration:return "تنظیمات اتصال iOS ناقص است";case .runtimeNotEmbedded:return "هسته iOS باید در مرحله امضای نهایی Embed شود" } }
}

/// Stable boundary for the forthcoming signed Xray/Aether XCFramework. Keeping
/// packet handling behind one adapter prevents UI/control-plane drift and lets
/// App Store builds swap the audited native runtime without touching screens.
final class BlueTunnelCore {
    private weak var packetFlow:NEPacketTunnelFlow?; private let mode:String; private let configuration:[String:Any]
    init(packetFlow:NEPacketTunnelFlow?,mode:String,configuration:[String:Any]){self.packetFlow=packetFlow;self.mode=mode;self.configuration=configuration}
    func start(completion:@escaping(Error?)->Void){
        // Fail closed: never report a fake connected state before the signed
        // Xray/Aether XCFramework has attached to NEPacketTunnelFlow.
        completion(TunnelError.runtimeNotEmbedded)
    }
    func stop(){}
}

