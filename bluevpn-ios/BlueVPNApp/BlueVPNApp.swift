import SwiftUI

@main
struct BlueVPNApp: App {
    @StateObject private var store = BlueVPNStore()
    var body: some Scene {
        WindowGroup {
            RootView().environmentObject(store).environment(\.layoutDirection, .rightToLeft)
        }
    }
}

struct RootView: View {
    @EnvironmentObject var store: BlueVPNStore
    var body: some View {
        NavigationStack(path: $store.path) {
            HomeView().navigationDestination(for: AppRoute.self) { route in
                switch route {
                case .locations: LocationsView()
                case .account: AccountView()
                case .auth: AuthView()
                case .plans: PlansView()
                case .support: SupportView()
                case .settings: SettingsView()
                }
            }
        }
        .task { await store.bootstrap() }
        .preferredColorScheme(store.theme.colorScheme)
    }
}
