import Foundation

enum BlueRuntimeContract {
    static let frameworkName = "BlueXrayCore.framework"
    static let requiredABI = 1
    static var embeddedRuntimeAvailable: Bool {
        guard let frameworks = Bundle.main.privateFrameworksPath else { return false }
        let root = URL(fileURLWithPath: frameworks).appendingPathComponent(frameworkName)
        let manifest = root.appendingPathComponent("BlueVPNRuntime.json")
        guard FileManager.default.fileExists(atPath:root.path),
              let data = try? Data(contentsOf:manifest),
              let json = try? JSONSerialization.jsonObject(with:data) as? [String:Any],
              (json["engine"] as? String) == "xray",
              (json["abi"] as? Int) == requiredABI else { return false }
        return true
    }
}
