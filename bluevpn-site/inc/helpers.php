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
