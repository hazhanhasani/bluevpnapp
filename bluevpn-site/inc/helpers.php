<?php
if (!defined('ABSPATH')) exit;
function bluevpn_site_brand(): string {
    if (has_custom_logo()) return get_custom_logo();
    return '<a class="bv-brand-text" href="'.esc_url(home_url('/')).'" aria-label="BlueVPN">BlueVPN</a>';
}
function bluevpn_site_support_url(): string {
    if (class_exists('BlueVPN_DB')) {
        $s = BlueVPN_DB::settings();
        if (!empty($s['support_url'])) return esc_url((string)$s['support_url']);
    }
    return esc_url(home_url('/support/'));
}
function bluevpn_site_mobile_config(): array {
    if (class_exists('BlueVPN_DB')) return BlueVPN_DB::settings();
    return [];
}

/**
 * Canonical public Windows release location.
 * Windows packages are intentionally distributed through GitHub Releases,
 * not Telegram, because the desktop self-contained bundles are large.
 */
function bluevpn_site_windows_release_repository(): string {
    $repo = (string) apply_filters('bluevpn_windows_release_repository', 'hazhanhasani/bluevpnapp');
    $repo = trim($repo, " /\t\n\r\0\x0B");
    return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : 'hazhanhasani/bluevpnapp';
}

function bluevpn_site_windows_downloads(?string $version = null): array {
    $version = trim((string)($version ?: BLUEVPN_SITE_VERSION));
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) $version = BLUEVPN_SITE_VERSION;

    $repo = bluevpn_site_windows_release_repository();
    $tag = 'bluevpn-windows-v' . $version;
    $base = 'https://github.com/' . $repo . '/releases/download/' . rawurlencode($tag) . '/';
    $release = 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode($tag);

    return [
        'version' => $version,
        'tag' => $tag,
        'release_url' => esc_url_raw($release),
        'x64_url' => esc_url_raw($base . rawurlencode('BlueVPN-Windows-' . $version . '-win-x64.zip')),
        'arm64_url' => esc_url_raw($base . rawurlencode('BlueVPN-Windows-' . $version . '-win-arm64.zip')),
    ];
}
