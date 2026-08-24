import Foundation

enum AppRoute: Hashable { case locations, account, auth, plans, support, settings }
enum ConnectionState: Equatable { case idle, preparing, connecting, verifying, connected, disconnecting, failed(String) }
enum PlanTier: String, Codable { case free, premium }
enum SelectionMode: String, Codable { case automatic, manual }
struct AccountSnapshot: Codable {
    var phone=""; var email=""; var active=false; var tier:PlanTier = .free; var locations=0; var remainingDays=0; var unlimited=false; var planTitle=""
    init() {}
    init(_ value:AccountPayload){ phone=value.phoneDisplay ?? value.phone ?? "";email=value.email ?? "";active=value.subscription?.active ?? false;tier=active ? .premium:.free;unlimited=value.subscription?.unlimited ?? false;planTitle=value.planTitle ?? "";if let seconds=value.subscription?.remainingSeconds{remainingDays=max(0,Int(ceil(Double(seconds)/86_400)))} }
}
struct LocationItem: Identifiable,Codable,Hashable { let id:String;let name:String;let countryCode:String;let flag:String;let subscriptionURL:String?;let priority:Int;var favorite:Bool=false }
struct Campaign: Identifiable,Codable { let id:String;let imageURL:String;let actionURL:String?;let title:String?; enum CodingKeys:String,CodingKey{case id,title;case imageURL="image_url";case actionURL="target_url"} }
struct AdvertisingPayload:Decodable{let enabled:Bool;let items:[Campaign]}
struct UpdatePolicy:Decodable{let channel:String;let automaticDownload,forceUpdate,betaTester:Bool;enum CodingKeys:String,CodingKey{case channel;case automaticDownload="automatic_download";case forceUpdate="force_update";case betaTester="beta_tester"}}
struct MobileConfig:Decodable{let advertising:AdvertisingPayload?;let freeAccess:FreeAccessPayload?;let updatePolicy:UpdatePolicy?;let latestVersion,releaseChannel:String?;let betaTester:Bool?;enum CodingKeys:String,CodingKey{case advertising;case freeAccess="free_access";case updatePolicy="update_policy";case latestVersion="latest_version";case releaseChannel="release_channel";case betaTester="beta_tester"}}
struct FreeAccessPayload:Decodable{let enabled:Bool;let engineMode:String?;let sources:[FreeSource]?;enum CodingKeys:String,CodingKey{case enabled;case engineMode="engine_mode";case sources}}
struct FreeSource:Decodable{let id,name:String;let subscriptionURL:String?;let priority:Int;enum CodingKeys:String,CodingKey{case id,name,priority;case subscriptionURL="subscription_url"}}
struct AccountEnvelope:Decodable{let success:Bool;let account:AccountPayload}
struct AuthEnvelope:Decodable{let success:Bool;let token,refreshToken:String;let account:AccountPayload;enum CodingKeys:String,CodingKey{case success,token,account;case refreshToken="refresh_token"}}
struct AccountPayload:Decodable{let email,phone,phoneDisplay,planTitle:String?;let subscription:SubscriptionPayload?;enum CodingKeys:String,CodingKey{case email,phone,subscription;case phoneDisplay="phone_display";case planTitle="plan_title"}}
struct SubscriptionPayload:Decodable{let active,unlimited:Bool;let remainingSeconds:Int?;let url:String?;enum CodingKeys:String,CodingKey{case active,unlimited,url;case remainingSeconds="remaining_seconds"}}
struct PlansEnvelope:Decodable{let success:Bool;let plans:[PlanItem]}
struct PlanItem:Identifiable,Decodable{let id:Int;let title,description:String;let priceToman,durationDays,dataLimitGB,deviceLimit:Int;enum CodingKeys:String,CodingKey{case id,title,description;case priceToman="price_toman";case durationDays="duration_days";case dataLimitGB="data_limit_gb";case deviceLimit="device_limit"}}
struct LoginBody:Encodable{let email,password:String;let deviceID,deviceName:String;enum CodingKeys:String,CodingKey{case email,password;case deviceID="device_id";case deviceName="device_name"}}
