import Foundation

enum APIError: LocalizedError {
    case invalidResponse, server(Int, String), decoding(Error)
    var errorDescription: String? {
        switch self {
        case .invalidResponse: return "پاسخ پنل معتبر نیست"
        case let .server(_, message): return message
        case .decoding: return "ساختار پاسخ پنل با برنامه سازگار نیست"
        }
    }
}

actor APIClient {
    static let shared = APIClient()
    let bases = [URL(string: "https://blluepanel.ir")!, URL(string: "https://bot.blluepanel.ir")!]
    var base: URL { bases[0] }
    private let session: URLSession = {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 12
        configuration.timeoutIntervalForResource = 25
        configuration.waitsForConnectivity = true
        return URLSession(configuration: configuration)
    }()
    func get<T: Decodable>(_ path: String, token: String? = nil, as: T.Type) async throws -> T { try await request(path, method: "GET", token: token, body: Optional<String>.none, as: T.self) }
    func text(from absoluteURL: String, token: String? = nil) async throws -> String {
        guard let url=URL(string:absoluteURL),url.scheme=="https",let host=url.host?.lowercased(),Self.isPublicHost(host) else { throw APIError.invalidResponse }
        var request=URLRequest(url:url);request.setValue("BlueVPN-iOS/6.1.5",forHTTPHeaderField:"User-Agent");request.setValue("text/plain,*/*;q=0.8",forHTTPHeaderField:"Accept")
        if let token,!token.isEmpty{request.setValue("Bearer \(token)",forHTTPHeaderField:"Authorization")}
        let (data,response)=try await session.data(for:request)
        guard let http=response as? HTTPURLResponse,200..<300 ~= http.statusCode,let text=String(data:data,encoding:.utf8),!text.trimmingCharacters(in:.whitespacesAndNewlines).isEmpty else {throw APIError.invalidResponse}
        return text
    }
    private static func isPublicHost(_ host:String)->Bool {
        if host=="localhost" || host=="::1" || host.hasSuffix(".local"){return false}
        let blocked=["10.","127.","169.254.","192.168."]
        if blocked.contains(where:host.hasPrefix){return false}
        if host.hasPrefix("172."),let second=Int(host.split(separator:".").dropFirst().first ?? ""),16...31 ~= second{return false}
        return true
    }
    func post<Body: Encodable, T: Decodable>(_ path: String, token: String? = nil, body: Body, as: T.Type) async throws -> T { try await request(path, method: "POST", token: token, body: body, as: T.self) }
    private func request<Body: Encodable, T: Decodable>(_ path: String, method: String, token: String?, body: Body?, as: T.Type) async throws -> T {
        let candidates = method == "GET" ? bases : [base]
        var lastError: Error = APIError.invalidResponse
        for candidate in candidates {
            do { return try await request(path, base: candidate, method: method, token: token, body: body, as: T.self) }
            catch let error as APIError {
                lastError = error
                if case let .server(status, _) = error, !(status == 502 || status == 503 || status == 504) { throw error }
            }
            catch { lastError = error }
        }
        throw lastError
    }
    private func request<Body: Encodable, T: Decodable>(_ path: String, base: URL, method: String, token: String?, body: Body?, as: T.Type) async throws -> T {
        guard let url = URL(string: path, relativeTo: base) else { throw APIError.invalidResponse }
        var request = URLRequest(url: url); request.httpMethod = method
        request.setValue("BlueVPN-iOS/6.1.5", forHTTPHeaderField: "User-Agent")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(DeviceIdentity.shared.id, forHTTPHeaderField: "X-Device-ID")
        if let token, !token.isEmpty { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        if let body { request.httpBody = try JSONEncoder().encode(body); request.setValue("application/json", forHTTPHeaderField: "Content-Type") }
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else { throw APIError.invalidResponse }
        guard 200..<300 ~= http.statusCode else { let detail = try? JSONDecoder().decode(APIErrorEnvelope.self, from: data); throw APIError.server(http.statusCode, detail?.detail?.message ?? "خطای پنل (\(http.statusCode))") }
        do { return try JSONDecoder().decode(T.self, from: data) } catch { throw APIError.decoding(error) }
    }
}
struct APIErrorEnvelope: Decodable { let detail: APIDetail? }
struct APIDetail: Decodable { let code: String?; let message: String? }
final class DeviceIdentity {
    static let shared = DeviceIdentity(); let id: String
    private init() { let key = "bluevpn_device_id"; if let saved = UserDefaults.standard.string(forKey:key), !saved.isEmpty { id=saved } else { id=UUID().uuidString.lowercased(); UserDefaults.standard.set(id,forKey:key) } }
}
