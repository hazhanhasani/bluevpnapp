import Foundation
import Security

struct SecureSession {
    static let shared=SecureSession();private let service="ir.blluepanel.bluevpn.session"
    var token:String?{read("access_token")};var refreshToken:String?{read("refresh_token")}
    func save(token:String,refreshToken:String){write(token,key:"access_token");write(refreshToken,key:"refresh_token")};func clear(){delete("access_token");delete("refresh_token")}
    private func read(_ key:String)->String?{var q=query(key);q[kSecReturnData as String]=true;q[kSecMatchLimit as String]=kSecMatchLimitOne;var result:AnyObject?;guard SecItemCopyMatching(q as CFDictionary,&result)==errSecSuccess,let data=result as? Data else{return nil};return String(data:data,encoding:.utf8)}
    private func write(_ value:String,key:String){delete(key);var q=query(key);q[kSecValueData as String]=Data(value.utf8);SecItemAdd(q as CFDictionary,nil)}
    private func delete(_ key:String){SecItemDelete(query(key) as CFDictionary)}
    private func query(_ key:String)->[String:Any]{[kSecClass as String:kSecClassGenericPassword,kSecAttrService as String:service,kSecAttrAccount as String:key,kSecAttrAccessible as String:kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly]}
}
