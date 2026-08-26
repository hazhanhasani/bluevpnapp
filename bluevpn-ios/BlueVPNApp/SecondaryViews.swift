import SwiftUI

struct AccountView: View {
    @EnvironmentObject private var store: BlueVPNStore

    var body: some View {
        Form {
            Section("حساب کاربری") {
                LabeledContent("شناسه", value: accountIdentifier)
                LabeledContent("وضعیت", value: store.account.active ? "فعال" : "مهمان")
                if !store.account.planTitle.isEmpty {
                    LabeledContent("پلن", value: store.account.planTitle)
                }
            }
            Section {
                if SecureSession.shared.token == nil {
                    Button("ورود یا ثبت‌نام") { store.path.append(.auth) }
                } else {
                    Button("تازه‌سازی حساب") { Task { await store.refreshAccount() } }
                    Button("خروج از حساب", role: .destructive) { Task { await store.logout() } }
                }
                Button("خرید و تمدید سرویس") { store.path.append(.plans) }
            }
        }
        .navigationTitle("حساب من")
    }

    private var accountIdentifier: String {
        if !store.account.phone.isEmpty { return store.account.phone }
        if !store.account.email.isEmpty { return store.account.email }
        return "وارد نشده"
    }
}

struct AuthView: View {
    @EnvironmentObject private var store: BlueVPNStore
    @Environment(\.dismiss) private var dismiss
    @State private var email = ""
    @State private var password = ""
    @State private var busy = false
    @State private var errorMessage = ""

    var body: some View {
        Form {
            Section("ورود امن") {
                TextField("ایمیل", text: $email)
                    .textInputAutocapitalization(.never)
                    .keyboardType(.emailAddress)
                SecureField("رمز عبور", text: $password)
            }
            if !errorMessage.isEmpty { Text(errorMessage).foregroundStyle(.red) }
            Button(busy ? "در حال ورود…" : "ورود") { Task { await login() } }
                .disabled(busy || email.isEmpty || password.isEmpty)
        }
        .navigationTitle("ورود BlueVPN")
    }

    @MainActor
    private func login() async {
        busy = true
        defer { busy = false }
        do {
            try await store.login(email: email, password: password)
            dismiss()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

struct PlansView: View {
    @EnvironmentObject private var store: BlueVPNStore

    var body: some View {
        ZStack {
            BlueBackground()
            ScrollView {
                VStack(spacing: 18) {
                    Text("پلن‌های BlueVPN").font(.largeTitle.bold())
                    if SecureSession.shared.token == nil {
                        GlassCard {
                            VStack(spacing: 12) {
                                Text("برای مشاهده قیمت‌های دلاری وارد شوید")
                                Button("ورود") { store.path.append(.auth) }
                                    .buttonStyle(.borderedProminent)
                            }
                        }
                    } else if store.plans.isEmpty {
                        ProgressView("دریافت پلن‌های دلاری…")
                            .task { await store.refreshPlans() }
                    } else {
                        ForEach(store.plans) { item in planCard(item) }
                    }
                }
                .padding()
            }
        }
        .navigationTitle("پلن‌ها")
    }

    private func planCard(_ item: PlanItem) -> some View {
        GlassCard {
            VStack(alignment: .leading, spacing: 12) {
                Text(item.title).font(.title2.bold())
                Text(item.description).foregroundStyle(.secondary)
                Text("\(item.durationDays) روز • \(item.deviceLimit) دستگاه").font(.caption)
                Button("انتخاب پلن") {}
                    .buttonStyle(.borderedProminent)
                    .tint(BlueColors.accent)
            }
        }
    }
}

struct SupportView: View {
    @State private var category = "اتصال و سرورها"
    @State private var message = ""

    var body: some View {
        Form {
            Picker("دپارتمان", selection: $category) {
                ForEach(["اشتراک و حساب", "مالی و پرداخت", "اتصال و سرورها", "نمایندگان", "سایر"], id: \.self) { Text($0) }
            }
            TextEditor(text: $message).frame(height: 180)
            Button("ارسال پیام") {}
        }
        .navigationTitle("پشتیبانی BlueVPN")
    }
}

struct SettingsView: View {
    @EnvironmentObject private var store: BlueVPNStore

    var body: some View {
        Form {
            Section("ظاهر برنامه") {
                Picker("پوسته", selection: Binding(get: { store.theme }, set: { store.setTheme($0) })) {
                    Text("همراه دستگاه").tag(BlueVPNThemeMode.system)
                    Text("روشن").tag(BlueVPNThemeMode.light)
                    Text("تیره").tag(BlueVPNThemeMode.dark)
                }
            }
            Section("انتشار") {
                LabeledContent("کانال", value: store.releaseChannel == "beta" ? "Beta" : "Stable")
                LabeledContent("کاربر بتا", value: store.betaTester ? "بله" : "خیر")
            }
            Section("BlueVPN") {
                Button("مکان‌ها") { store.path.append(.locations) }
                Button("پلن‌ها") { store.path.append(.plans) }
                Button("پشتیبانی") { store.path.append(.support) }
                LabeledContent("نسخه", value: "5.10.1")
            }
        }
        .navigationTitle("تنظیمات")
    }
}
