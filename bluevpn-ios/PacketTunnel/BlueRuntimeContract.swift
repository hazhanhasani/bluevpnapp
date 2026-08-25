import Foundation

enum BlueRuntimeContract {
    static let frameworkName = "BlueXrayCore.xcframework"
    static let requiredABI = 1
    static var embeddedRuntimeAvailable: Bool {
        guard let manifest = Bundle.main.url(forResource:"BlueVPNRuntime",withExtension:"json"),
              let data = try? Data(contentsOf:manifest),
              let json = try? JSONSerialization.jsonObject(with:data) as? [String:Any],
              (json["engine"] as? String) == "xray",
              (json["abi"] as? Int) == requiredABI,
              (json["api_version"] as? Int) == 2 else { return false }
        return true
    }
}
