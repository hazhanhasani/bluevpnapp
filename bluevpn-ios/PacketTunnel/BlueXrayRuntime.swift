import Foundation

#if canImport(LibXray)
import Darwin
import LibXray
#endif

struct BlueXrayResponse: Decodable {
    let success: Bool
    let data: JSONValue?
    let error: String
}

enum JSONValue: Decodable {
    case string(String), bool(Bool), number(Double), object([String: JSONValue]), array([JSONValue]), null

    init(from decoder: Decoder) throws {
        let value = try decoder.singleValueContainer()
        if value.decodeNil() { self = .null }
        else if let item = try? value.decode(Bool.self) { self = .bool(item) }
        else if let item = try? value.decode(Double.self) { self = .number(item) }
        else if let item = try? value.decode(String.self) { self = .string(item) }
        else if let item = try? value.decode([String: JSONValue].self) { self = .object(item) }
        else { self = .array(try value.decode([JSONValue].self)) }
    }
}

enum BlueXrayError: LocalizedError {
    case runtimeUnavailable, invalidResponse, invocation(String), configurationUnavailable

    var errorDescription: String? {
        switch self {
        case .runtimeUnavailable: return "BlueXrayCore ممیزی‌شده داخل Tunnel قرار نگرفته است"
        case .invalidResponse: return "پاسخ BlueXrayCore معتبر نیست"
        case .invocation(let message): return "خطای Xray: \(message)"
        case .configurationUnavailable: return "کانفیگ معتبر Xray برای این لوکیشن دریافت نشد"
        }
    }
}

final class BlueXrayRuntime {
    func validate(configuration: String) throws {
        _ = try invoke(method: "testXray", payload: ["xrayJson": configuration])
    }

    func run(configuration: String) throws {
        _ = try invoke(method: "runXray", payload: ["xrayJson": configuration])
    }

    func stop() {
        _ = try? invoke(method: "stopXray", payload: [:])
    }

    func convertSubscription(_ text: String) throws -> String {
        let response = try invoke(method: "convertShareLinksToXrayJson", payload: ["text": normalizedSubscription(text)])
        guard case .string(let config)? = response.data, !config.isEmpty else { throw BlueXrayError.invalidResponse }
        return config
    }

    private func invoke(method: String, payload: [String: String]) throws -> BlueXrayResponse {
        let request: [String: Any] = ["apiVersion": 2, "method": method, "payload": payload]
        let data = try JSONSerialization.data(withJSONObject: request)
        guard let json = String(data: data, encoding: .utf8) else { throw BlueXrayError.invalidResponse }
#if canImport(LibXray)
        guard let input = strdup(json) else { throw BlueXrayError.invalidResponse }
        defer { free(input) }
        guard let output = CGoInvoke(input) else { throw BlueXrayError.invalidResponse }
        defer { CGoFree(output) }
        let responseData = Data(String(cString: output).utf8)
        let response = try JSONDecoder().decode(BlueXrayResponse.self, from: responseData)
        guard response.success else { throw BlueXrayError.invocation(response.error) }
        return response
#else
        throw BlueXrayError.runtimeUnavailable
#endif
    }

    private func normalizedSubscription(_ text: String) -> String {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        if trimmed.contains("://") { return trimmed }
        let padded = trimmed.padding(toLength: ((trimmed.count + 3) / 4) * 4, withPad: "=", startingAt: 0)
        return Data(base64Encoded: padded).flatMap { String(data: $0, encoding: .utf8) } ?? trimmed
    }
}
