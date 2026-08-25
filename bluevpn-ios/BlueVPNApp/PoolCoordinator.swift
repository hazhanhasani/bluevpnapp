import Foundation

enum PoolTier: String, Codable { case free, premium }
enum PoolCoordinatorError: LocalizedError {
    case configurationUnavailable
    var errorDescription: String? { "هیچ Pool قابل استفاده‌ای از پنل یا کش دریافت نشد" }
}

struct PoolSource: Identifiable, Codable, Hashable {
    let id: String
    let name: String
    let url: String
    let priority: Int
    let tier: PoolTier
    let countryCode: String
}

struct CachedPool: Codable {
    let source: PoolSource
    var content: String
    var fetchedAt: Date
    var expiresAt: Date
}

struct PoolHealth: Codable {
    var successes = 0
    var failures = 0
    var lastLatencyMS = 0
    var lastSuccessAt: Date?
    var lastFailureAt: Date?
}

actor PoolCoordinator {
    static let shared = PoolCoordinator()
    private var cache: [String: CachedPool] = [:]
    private var health: [String: PoolHealth] = [:]
    private let cacheTTL: TimeInterval = 15 * 60
    private let staleTTL: TimeInterval = 7 * 24 * 60 * 60
    private let storageURL: URL?

    init() {
        let root = FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: "group.ir.blluepanel.bluevpn")
        storageURL = root?.appendingPathComponent("pool-cache-v1.json")
        if let storageURL,let data=try? Data(contentsOf:storageURL),let stored=try? JSONDecoder().decode(Storage.self,from:data){cache=stored.cache;health=stored.health}
    }

    func ranked(_ sources: [PoolSource]) -> [PoolSource] {
        sources.sorted {
            let left=score($0),right=score($1)
            return left==right ? ($0.priority == $1.priority ? $0.name < $1.name : $0.priority < $1.priority) : left > right
        }
    }

    func resolve(_ sources: [PoolSource], token: String?) async throws -> CachedPool {
        let ordered=ranked(sources)
        guard !ordered.isEmpty else {throw PoolCoordinatorError.configurationUnavailable}
        for source in ordered {
            if let item=cache[source.id],item.expiresAt>Date(){Task{try? await self.refresh(source,token:token)};return item}
        }
        for source in ordered {
            if let item=try? await refresh(source,token:token){return item}
        }
        if let stale=ordered.compactMap({cache[$0.id]}).filter({Date().timeIntervalSince($0.fetchedAt)<staleTTL}).sorted(by:{$0.fetchedAt>$1.fetchedAt}).first{return stale}
        throw PoolCoordinatorError.configurationUnavailable
    }

    func warm(_ sources: [PoolSource], token: String?) async {
        await withTaskGroup(of:Void.self){group in for source in ranked(sources).prefix(4){group.addTask{_ = try? await self.refresh(source,token:token)}}}
    }

    func record(sourceID:String,success:Bool,latencyMS:Int=0){
        var value=health[sourceID] ?? PoolHealth()
        if success{value.successes+=1;value.lastSuccessAt=Date();if latencyMS>0{value.lastLatencyMS=latencyMS}}else{value.failures+=1;value.lastFailureAt=Date()}
        health[sourceID]=value;save()
    }

    private func refresh(_ source:PoolSource,token:String?) async throws -> CachedPool {
        let content=try await APIClient.shared.text(from:source.url,token:token)
        let item=CachedPool(source:source,content:content,fetchedAt:Date(),expiresAt:Date().addingTimeInterval(cacheTTL));cache[source.id]=item;save();return item
    }

    private func score(_ source:PoolSource)->Int{
        let value=health[source.id] ?? PoolHealth();var result=50-min(25,max(-25,source.priority));result+=min(25,value.successes*3);result-=min(45,value.failures*8)
        if value.lastLatencyMS>0{result+=max(-20,20-value.lastLatencyMS/20)}
        if let last=value.lastSuccessAt,Date().timeIntervalSince(last)<86400{result+=12};if let last=value.lastFailureAt,Date().timeIntervalSince(last)<900{result-=20};return result
    }

    private func save(){guard let storageURL else{return};try? FileManager.default.createDirectory(at:storageURL.deletingLastPathComponent(),withIntermediateDirectories:true);if let data=try? JSONEncoder().encode(Storage(cache:cache,health:health)){try? data.write(to:storageURL,options:.atomic)}}
    private struct Storage:Codable{let cache:[String:CachedPool];let health:[String:PoolHealth]}
}

enum CountryDetector {
    static func code(for text:String)->String{let value=text.lowercased();let map=[("آلمان","DE"),("germany","DE"),("ترکیه","TR"),("turkey","TR"),("هلند","NL"),("netherlands","NL"),("آمریکا","US"),("usa","US"),("انگلیس","GB"),("uk","GB"),("فرانسه","FR"),("سنگاپور","SG"),("ژاپن","JP"),("امارات","AE"),("روسیه","RU")];return map.first(where:{value.contains($0.0)})?.1 ?? ""}
}
