import Foundation

enum AppRoute: Hashable { case locations, account, plans, support, settings }
enum ConnectionState: Equatable { case idle, preparing, connecting, verifying, connected, disconnecting, failed(String) }
enum PlanTier: String, Codable { case free, premium }
enum SelectionMode: String, Codable { case automatic, manual }

struct AccountSnapshot: Codable { var phone = ""; var active = false; var tier: PlanTier = .free; var locations = 0; var remainingDays = 0; var unlimited = false }
struct LocationItem: Identifiable, Codable, Hashable { let id: String; let name: String; let countryCode: String; let flag: String; let subscriptionURL: String?; let priority: Int; var favorite: Bool = false }
struct Campaign: Identifiable, Codable { let id: String; let imageURL: String; let actionURL: String?; let title: String? }
struct FreeWarpPolicy: Codable { let enabled: Bool; let ipMode: String; let allowedTransports: [String]; let quickReconnect: Bool; let fallbackPoolEnabled: Bool
    enum CodingKeys: String, CodingKey { case enabled; case ipMode = "ip_mode"; case allowedTransports = "allowed_transports"; case quickReconnect = "quick_reconnect"; case fallbackPoolEnabled = "fallback_pool_enabled" }
}
struct ControlPlaneEnvelope: Codable { var locations: [LocationItem]?; var campaigns: [Campaign]?; var warp: FreeWarpPolicy? }

