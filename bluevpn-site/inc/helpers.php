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
 * Canonical public Windows release repository.
 * Windows packages are distributed through GitHub Releases rather than Telegram.
 */
function bluevpn_site_windows_release_repository(): string {
    $repo = (string) apply_filters('bluevpn_windows_release_repository', 'hazhanhasani/bluevpnapp');
    $repo = trim($repo, " /\t\n\r\0\x0B");
    return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo) ? $repo : 'hazhanhasani/bluevpnapp';
}

function bluevpn_site_windows_empty_release(): array {
    return [
        'available' => false,
        'version' => '',
        'tag' => '',
        'channel' => 'unknown',
        'channel_label' => 'وضعیت نامشخص',
        'prerelease' => false,
        'release_url' => '',
        'x64_url' => '',
        'arm64_url' => '',
        'published_at' => '',
    ];
}

/**
 * Read the latest actual Windows release from GitHub.
 *
 * Important: Windows has its own release/version/channel lifecycle. The theme
 * version is never used as the Windows package version. A release is considered
 * channel-defined only when the workflow writes BlueVPN-Windows-Channel into
 * its release notes. This keeps pre-4.16.1 releases from being mislabeled as
 * Stable just because GitHub's prerelease flag was not used yet.
 */
function bluevpn_site_windows_downloads(?string $version = null, bool $force = false): array {
    $requested = trim((string)$version);
    if ($requested !== '' && !preg_match('/^\d+\.\d+\.\d+$/', $requested)) $requested = '';

    $repo = bluevpn_site_windows_release_repository();
    $cacheSuffix = md5($repo . '|' . ($requested ?: 'latest'));
    $cacheKey = 'bluevpn_windows_release_v3_' . $cacheSuffix;
    $lastGoodKey = 'bluevpn_windows_release_last_good_v3_' . $cacheSuffix;

    if (!$force) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return array_merge(bluevpn_site_windows_empty_release(), $cached);
    }

    $api = 'https://api.github.com/repos/' . $repo . '/releases?per_page=30';
    $response = wp_remote_get($api, [
        'timeout' => 6,
        'redirection' => 3,
        'headers' => [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'BlueVPN-Site/' . (defined('BLUEVPN_SITE_VERSION') ? BLUEVPN_SITE_VERSION : 'unknown'),
        ],
    ]);

    if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
        $lastGood = get_option($lastGoodKey, []);
        return is_array($lastGood) ? array_merge(bluevpn_site_windows_empty_release(), $lastGood) : bluevpn_site_windows_empty_release();
    }

    $releases = json_decode((string)wp_remote_retrieve_body($response), true);
    if (!is_array($releases)) return bluevpn_site_windows_empty_release();

    foreach ($releases as $release) {
        if (!is_array($release) || !empty($release['draft'])) continue;
        $tag = trim((string)($release['tag_name'] ?? ''));
        if (!preg_match('/^bluevpn-windows-v(\d+\.\d+\.\d+)$/', $tag, $m)) continue;
        $releaseVersion = $m[1];
        if ($requested !== '' && $releaseVersion !== $requested) continue;

        $x64 = '';
        $arm64 = '';
        foreach ((array)($release['assets'] ?? []) as $asset) {
            if (!is_array($asset)) continue;
            $name = (string)($asset['name'] ?? '');
            $url = (string)($asset['browser_download_url'] ?? '');
            if ($url === '' || !wp_http_validate_url($url)) continue;
            if ($name === 'BlueVPN-Windows-' . $releaseVersion . '-win-x64.zip') $x64 = $url;
            if ($name === 'BlueVPN-Windows-' . $releaseVersion . '-win-arm64.zip') $arm64 = $url;
        }
        if ($x64 === '' || $arm64 === '') continue;

        $body = (string)($release['body'] ?? '');
        $channel = 'unknown';
        if (preg_match('/BlueVPN-Windows-Channel:\s*(stable|beta)/i', $body, $channelMatch)) {
            $channel = strtolower($channelMatch[1]);
        }
        $prerelease = !empty($release['prerelease']);
        // The marker is authoritative. prerelease is retained as an additional diagnostic.
        $label = $channel === 'stable' ? 'پایدار' : ($channel === 'beta' ? 'آزمایشی (Beta)' : 'وضعیت نامشخص');

        $result = [
            'available' => true,
            'version' => $releaseVersion,
            'tag' => $tag,
            'channel' => $channel,
            'channel_label' => $label,
            'prerelease' => $prerelease,
            'release_url' => esc_url_raw((string)($release['html_url'] ?? '')),
            'x64_url' => esc_url_raw($x64),
            'arm64_url' => esc_url_raw($arm64),
            'published_at' => sanitize_text_field((string)($release['published_at'] ?? '')),
        ];
        set_transient($cacheKey, $result, 15 * MINUTE_IN_SECONDS);
        update_option($lastGoodKey, $result, false);
        return $result;
    }

    $empty = bluevpn_site_windows_empty_release();
    set_transient($cacheKey, $empty, 5 * MINUTE_IN_SECONDS);
    return $empty;
}
