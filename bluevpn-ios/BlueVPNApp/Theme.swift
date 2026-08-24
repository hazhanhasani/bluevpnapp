import SwiftUI

enum BlueVPNThemeMode: String, CaseIterable { case system, dark, light
    var colorScheme: ColorScheme? { self == .system ? nil : (self == .dark ? .dark : .light) }
}

enum BlueColors {
    static let accent = Color(hex: 0x356DF1)
    static let accentStrong = Color(hex: 0x2455CC)
    static let success = Color(hex: 0x118A67)
    static let warning = Color(hex: 0xFFB454)
    static let danger = Color(hex: 0xC43F59)
    static let lightBackground = Color(hex: 0xF6F8FC)
    static let darkBackground = Color(hex: 0x08090E)
}

extension Color { init(hex: UInt) { self.init(.sRGB, red: Double((hex >> 16) & 255)/255, green: Double((hex >> 8) & 255)/255, blue: Double(hex & 255)/255, opacity: 1) } }

struct GlassCard<Content: View>: View {
    @Environment(\.colorScheme) var scheme
    let content: Content
    init(@ViewBuilder content: () -> Content) { self.content = content() }
    var body: some View { content.padding(18).background(scheme == .dark ? Color(hex: 0x121319) : .white).clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous)).overlay(RoundedRectangle(cornerRadius: 24).stroke(scheme == .dark ? Color(hex: 0x2A2D38) : Color(hex: 0xC8D1E2), lineWidth: 1)).shadow(color: .black.opacity(0.06), radius: 8, y: 4) }
}

struct BlueBackground: View { @Environment(\.colorScheme) var scheme
    var body: some View { (scheme == .dark ? BlueColors.darkBackground : BlueColors.lightBackground).overlay(RadialGradient(colors: [BlueColors.accent.opacity(0.10), .clear], center: .top, startRadius: 20, endRadius: 430)).ignoresSafeArea() }
}

