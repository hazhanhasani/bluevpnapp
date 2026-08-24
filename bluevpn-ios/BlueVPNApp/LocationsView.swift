import SwiftUI

struct LocationsView: View { @EnvironmentObject var store:BlueVPNStore; @Environment(\.dismiss) var dismiss; @State private var query=""; @State private var tab=0
    var filtered:[LocationItem] { store.locations.filter { query.isEmpty || $0.name.localizedCaseInsensitiveContains(query) }.filter { tab != 1 || $0.favorite } }
    var body: some View { ZStack { BlueBackground(); VStack(spacing:16) { HStack { Button("بستن"){dismiss()}.buttonStyle(.bordered); Spacer(); VStack { Text("مکان‌ها").font(.largeTitle.bold()); Text("انتخاب هوشمند و انتخاب دستی").foregroundStyle(.secondary) }; Spacer(); Button("تازه‌سازی"){Task{await store.refreshControlPlane()}}.buttonStyle(.bordered) }
        Picker("",selection:$tab){Text("همه").tag(0);Text("علاقه‌مندی").tag(1);Text("اخیر").tag(2)}.pickerStyle(.segmented)
        TextField("جستجوی کشور",text:$query).padding().background(.background).clipShape(RoundedRectangle(cornerRadius:18))
        ScrollView { LazyVStack(spacing:14) { Button { store.selected=nil; dismiss() } label:{ locationCard(flag:"●",name:"انتخاب خودکار",caption:"بهترین اتصال داخل اپلیکیشن انتخاب می‌شود",active:store.selected==nil) }.buttonStyle(.plain)
            ForEach(filtered) { item in Button { store.selected=item; dismiss() } label:{ locationCard(flag:item.flag,name:item.name,caption:"آماده اتصال",active:store.selected?.id==item.id) }.buttonStyle(.plain) }
        }}
    }.padding(20) }.toolbar(.hidden,for:.navigationBar) }
    func locationCard(flag:String,name:String,caption:String,active:Bool)->some View { GlassCard { HStack { Text(flag).font(.system(size:42)); VStack(alignment:.leading){Text(name).font(.title2.bold());Text(caption).foregroundStyle(.secondary)};Spacer();Text(active ? "فعال":"انتخاب").foregroundStyle(active ? BlueColors.accent:.secondary) }.overlay(alignment:.leading){ if active { RoundedRectangle(cornerRadius:3).fill(BlueColors.accent).frame(width:4) } } } }
}

