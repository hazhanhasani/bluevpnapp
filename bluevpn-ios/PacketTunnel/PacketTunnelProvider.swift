import Foundation
import NetworkExtension
import Network
import SwiftyXrayKit

final class PacketTunnelProvider: NEPacketTunnelProvider {
    private var core: BlueTunnelCore?
    private let pathMonitor=NWPathMonitor();private let monitorQueue=DispatchQueue(label:"ir.blluepanel.bluevpn.path")
    private var lastPathSignature="";private var restartWorkItem:DispatchWorkItem?
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
            runtime.start { [weak self] runtimeError in
                if runtimeError == nil { self?.startPathMonitoring() }
                completionHandler(runtimeError)
            }
        }
    }
    override func stopTunnel(with reason: NEProviderStopReason, completionHandler: @escaping () -> Void) { restartWorkItem?.cancel();pathMonitor.cancel();core?.stop();core=nil;completionHandler() }
    override func sleep(completionHandler:@escaping()->Void){core?.stop();completionHandler()}
    override func wake(){restartCoreAfterPathChange()}
    private func startPathMonitoring(){
        pathMonitor.pathUpdateHandler={ [weak self] path in
            guard let self else{return};let signature="\(path.status)-\(path.usesInterfaceType(.wifi))-\(path.usesInterfaceType(.cellular))-\(path.isExpensive)"
            guard !lastPathSignature.isEmpty,signature != lastPathSignature,path.status == .satisfied else{lastPathSignature=signature;return}
            lastPathSignature=signature;restartCoreAfterPathChange()
        }
        pathMonitor.start(queue:monitorQueue)
    }
    private func restartCoreAfterPathChange(){
        restartWorkItem?.cancel();let item=DispatchWorkItem{ [weak self] in
            guard let self,let core else{return};reasserting=true;core.restart{ [weak self] _ in self?.reasserting=false }
        };restartWorkItem=item;monitorQueue.asyncAfter(deadline:.now()+1.2,execute:item)
    }
}

enum TunnelError: LocalizedError { case invalidConfiguration, runtimeNotEmbedded, packetBridgeNotEmbedded
    var errorDescription:String? { switch self { case .invalidConfiguration:return "تنظیمات اتصال iOS ناقص است";case .runtimeNotEmbedded:return "هسته iOS باید در مرحله امضای نهایی Embed شود";case .packetBridgeNotEmbedded:return "Packet Bridge ممیزی‌شده iOS هنوز Embed نشده است" } }
}

/// Stable boundary for the forthcoming signed Xray/Aether XCFramework. Keeping
/// packet handling behind one adapter prevents UI/control-plane drift and lets
/// App Store builds swap the audited native runtime without touching screens.
final class BlueTunnelCore {
    private weak var packetFlow:NEPacketTunnelFlow?; private let mode:String; private let configuration:[String:Any];private var bridge:XrayBridge?
    init(packetFlow:NEPacketTunnelFlow?,mode:String,configuration:[String:Any]){self.packetFlow=packetFlow;self.mode=mode;self.configuration=configuration}
    func start(completion:@escaping(Error?)->Void){
        Task {
            do {
                guard let packetFlow else { throw TunnelError.packetBridgeNotEmbedded }
                let xrayJSON = try await resolveXrayConfiguration()
                let root=FileManager.default.containerURL(forSecurityApplicationGroupIdentifier:"group.ir.blluepanel.bluevpn") ?? FileManager.default.temporaryDirectory
                let runtimeDir=root.appendingPathComponent("ios-xray-runtime",isDirectory:true)
                try FileManager.default.createDirectory(at:runtimeDir,withIntermediateDirectories:true)
                let finalConfig=runtimeDir.appendingPathComponent("active.json")
                let activeBridge=XrayBridge(packetFlow:packetFlow)
                try activeBridge.start(config:.json(xrayJSON),dataDir:runtimeDir,finalConfigPath:finalConfig,preset:.mobile,configTransform:{ config in
                    var value=config;value["log"]=["loglevel":"warning"]
                    value["dns"]=["servers":["1.1.1.1","1.0.0.1"],"queryStrategy":"UseIPv4"]
                    return value
                })
                bridge=activeBridge
                completion(nil)
            } catch { bridge?.stop();bridge=nil;completion(error) }
        }
    }
    func stop(){bridge?.stop();bridge=nil}
    func restart(completion:@escaping(Error?)->Void){stop();start(completion:completion)}
    private func resolveXrayConfiguration() async throws -> String {
        if let value=configuration["xray_json"] as? String,!value.isEmpty{return value}
        if let value=configuration["subscription_text"] as? String,!value.isEmpty{
            let normalized=normalizedSubscription(value)
            if normalized.trimmingCharacters(in:.whitespacesAndNewlines).hasPrefix("{"){return normalized}
            return try SwiftyXray.xrayShareLinkToJson(url:normalized)
        }
        guard mode=="xray",let raw=configuration["subscription_url"] as? String,let url=URL(string:raw),url.scheme=="https" else {throw TunnelError.invalidConfiguration}
        let (data,response)=try await URLSession.shared.data(from:url)
        guard (response as? HTTPURLResponse)?.statusCode==200,let text=String(data:data,encoding:.utf8),!text.isEmpty else {throw TunnelError.invalidConfiguration}
        let normalized=normalizedSubscription(text)
        if normalized.trimmingCharacters(in:.whitespacesAndNewlines).hasPrefix("{"){return normalized}
        return try SwiftyXray.xrayShareLinkToJson(url:normalized)
    }
    private func normalizedSubscription(_ text:String)->String {
        let trimmed=text.trimmingCharacters(in:.whitespacesAndNewlines)
        if trimmed.contains("://"){return trimmed}
        let padded=trimmed.padding(toLength:((trimmed.count+3)/4)*4,withPad:"=",startingAt:0)
        return Data(base64Encoded:padded).flatMap{String(data:$0,encoding:.utf8)} ?? trimmed
    }
}
