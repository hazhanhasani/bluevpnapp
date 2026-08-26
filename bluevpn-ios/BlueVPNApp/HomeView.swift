import SwiftUI

struct HomeView: View {
    @EnvironmentObject var store: BlueVPNStore
    var body: some View { ZStack { BlueBackground(); ScrollView(showsIndicators: false) { VStack(spacing: 18) {
        HStack { Button { store.path.append(.account) } label: { Image(systemName: "person").font(.title2).frame(width: 54,height:54).background(.thinMaterial).clipShape(RoundedRectangle(cornerRadius:18)) }; Spacer(); Text("5.10.10").foregroundStyle(.secondary); Button { store.path.append(.settings) } label: { Image(systemName:"line.3.horizontal").font(.title2).frame(width:54,height:54).background(.thinMaterial).clipShape(RoundedRectangle(cornerRadius:18)) } }
        Text("BlueVPN").font(.system(size: 42, weight: .bold, design: .rounded)).italic().foregroundStyle(BlueColors.accent)
        Text(store.account.active ? "فعال • اشتراک \(store.account.tier == .premium ? "Premium" : "رایگان") • \(store.account.locations) لوکیشن" : "حالت مهمان • اتصال رایگان").foregroundStyle(.secondary)
        VStack(spacing:8) { HStack(spacing:10) { Circle().fill(statusColor).frame(width:13,height:13); Text(store.statusTitle).font(.title.bold()) }; Text(store.statusCaption).font(.subheadline).foregroundStyle(.secondary).multilineTextAlignment(.center) }
        Button { Task { await store.toggle() } } label: { HStack { Text(buttonTitle).font(.title3.bold()).frame(maxWidth:.infinity); ZStack { Circle().fill(statusColor); Image(systemName:"power").font(.system(size:40,weight:.medium)).foregroundStyle(.white) }.frame(width:102,height:102) }.padding(10).background(statusColor.opacity(0.08)).clipShape(Capsule()).overlay(Capsule().stroke(statusColor.opacity(0.55))) }.buttonStyle(.plain)
        HStack { Metric(title:"دانلود",value:store.download,icon:"arrow.down"); Metric(title:"مدت اتصال",value:store.duration,icon:"clock"); Metric(title:"آپلود",value:store.upload,icon:"arrow.up") }
        CampaignView(campaigns: store.campaigns)
        Button { store.path.append(.locations) } label: { GlassCard { HStack(spacing:16) { Image(systemName:"line.3.horizontal").font(.title2).foregroundStyle(.secondary); VStack(alignment:.leading,spacing:6) { Text(store.selected?.name ?? "انتخاب خودکار").font(.title3.bold()); Text(store.selected == nil ? "بهترین مسیر با پینگ و سلامت شبکه انتخاب می‌شود" : "آماده اتصال").font(.caption).foregroundStyle(.secondary) }; Spacer(); Circle().fill(BlueColors.accent).frame(width:14,height:14) } } }.buttonStyle(.plain)
        GlassCard { HStack { VStack { Text("حجم باقی‌مانده").font(.caption).foregroundStyle(.secondary); Text(store.account.unlimited ? "نامحدود" : "—").bold() }; Spacer(); Divider().frame(height:42); Spacer(); VStack { Text("زمان باقی‌مانده").font(.caption).foregroundStyle(.secondary); Text(store.account.remainingDays > 0 ? "\(store.account.remainingDays) روز" : "—").bold() } } }
    }.padding(.horizontal,22).padding(.bottom,30) } }.toolbar(.hidden, for:.navigationBar) }
    var statusColor: Color { switch store.state { case .connected: return BlueColors.success; case .failed: return BlueColors.danger; case .connecting,.preparing,.verifying,.disconnecting: return BlueColors.warning; default:return BlueColors.accent } }
    var buttonTitle: String { store.state == .connected ? "قطع اتصال" : (store.state == .connecting ? "در حال اتصال…" : "اتصال") }
}

struct Metric: View { let title,value,icon:String; var body: some View { VStack(spacing:6) { Label(title,systemImage:icon).font(.caption).foregroundStyle(.secondary); Text(value).font(.subheadline.bold()).lineLimit(1) }.frame(maxWidth:.infinity).padding(.vertical,12) } }
struct CampaignView: View { let campaigns:[Campaign]; var body: some View { Group { if let c=campaigns.first, let u=URL(string:c.imageURL) { AsyncImage(url:u) { image in image.resizable().scaledToFill() } placeholder: { adPlaceholder }.frame(height:150).clipped().clipShape(RoundedRectangle(cornerRadius:22)) } else { adPlaceholder } } }
    var adPlaceholder: some View { RoundedRectangle(cornerRadius:22).fill(LinearGradient(colors:[Color(hex:0x061437),Color(hex:0x164DBE)],startPoint:.leading,endPoint:.trailing)).frame(height:150).overlay(VStack { Text("BlueVPN").font(.title.bold()); Text("اتصال سریع، امن و پایدار") }.foregroundStyle(.white)) }
}

