import Foundation

actor APIClient {
    static let shared = APIClient()
    let base = URL(string: "https://bot.blluepanel.ir")!
    private let session: URLSession = { let c = URLSessionConfiguration.ephemeral; c.timeoutIntervalForRequest = 12; c.timeoutIntervalForResource = 25; c.waitsForConnectivity = true; return URLSession(configuration: c) }()
    func get<T: Decodable>(_ path: String, token: String? = nil, as: T.Type) async throws -> T {
        var request = URLRequest(url: URL(string: path, relativeTo: base)!)
        request.setValue("BlueVPN-iOS/5.6.1", forHTTPHeaderField: "User-Agent")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token { request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization") }
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse, 200..<300 ~= http.statusCode else { throw URLError(.badServerResponse) }
        return try JSONDecoder().decode(T.self, from: data)
    }
}

