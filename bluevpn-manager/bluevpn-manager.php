<?php
/**
 * Plugin Name: BlueVPN Manager
 * Description: هسته حساب کاربری، اشتراک، پرداخت، پشتیبانی آنلاین و API سرویس BlueVPN.
 * Version: 4.15.7
 * Author: BlueVPN
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Text Domain: bluevpn-manager
 * Update URI: https://bot.blluepanel.ir/bluevpn-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BLUEVPN_MANAGER_VERSION', '4.15.7');
define('BLUEVPN_MANAGER_SCHEMA_VERSION', '1.22.0');
define('BLUEVPN_MANAGER_FILE', __FILE__);
define('BLUEVPN_MANAGER_DIR', plugin_dir_path(__FILE__));
define('BLUEVPN_MANAGER_URL', plugin_dir_url(__FILE__));

require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-utils.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-db.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-auth.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-sms-otp.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-sms-notifications.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-manual-customers.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-ads.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-free-sources.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-ai.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-ai-ops.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-payments.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-api.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-providers.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-control-center.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-compat.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-cron.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-unified-ui.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-frontend.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-admin.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-app-release-manager.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-github-updater.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-telegram-bot.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-support.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-migration.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-production.php';

register_activation_hook(__FILE__, function () {
    BlueVPN_DB::activate();
    BlueVPN_Compat::register_rewrites();
    flush_rewrite_rules(false);
    BlueVPN_Cron::schedule();
    BlueVPN_SMS_Notifications::seed_templates();
    BlueVPN_SMS_Notifications::schedule();
    BlueVPN_Migration::sync_cron_schedule(!empty(BlueVPN_Migration::settings()['auto_sync']));
    BlueVPN_Migration::sync_auto_schedule(!empty(BlueVPN_Migration::settings()['auto_migrate']));
    BlueVPN_App_Release_Manager::ensure_schedule();
    BlueVPN_GitHub_Updater::ensure_schedule();
    BlueVPN_Telegram_Bot::activate();
    BlueVPN_Support::activate();
    BlueVPN_Production::activate();
    BlueVPN_Free_Sources::seed();
});

register_deactivation_hook(__FILE__, function () {
    BlueVPN_Cron::unschedule();
    BlueVPN_SMS_Notifications::unschedule();
    BlueVPN_Migration::sync_cron_schedule(false);
    BlueVPN_Migration::sync_auto_schedule(false);
    BlueVPN_App_Release_Manager::unschedule();
    BlueVPN_GitHub_Updater::unschedule();
    BlueVPN_Telegram_Bot::deactivate();
    BlueVPN_Production::deactivate();
    wp_clear_scheduled_hook('bluevpn_ai_ops_tick');
    flush_rewrite_rules(false);
});


// Refresh compatibility rewrite rules once per plugin version. Plugin updates do
// not run the activation hook, so /api/v1/* could otherwise remain unavailable
// until Permalinks were saved manually.
add_action('init', function () {
    $key = 'bluevpn_manager_rewrite_version';
    if ((string)get_option($key, '') !== BLUEVPN_MANAGER_VERSION) {
        BlueVPN_Compat::register_rewrites();
        flush_rewrite_rules(false);
        update_option($key, BLUEVPN_MANAGER_VERSION, false);
    }
}, 99);

add_action('plugins_loaded', function () {
    BlueVPN_DB::maybe_upgrade();
    BlueVPN_SMS_Notifications::init();
    BlueVPN_Manual_Customers::init();
    BlueVPN_Unified_UI::init();
    BlueVPN_Frontend::init();
    BlueVPN_Ads::init();
    BlueVPN_Free_Sources::init();
    BlueVPN_AI::init();
    BlueVPN_AI_Ops::init();
    BlueVPN_API::init();
    BlueVPN_Providers::init();
    BlueVPN_Control_Center::init();
    BlueVPN_Compat::init();
    BlueVPN_Cron::init();
    BlueVPN_Admin::init();
    BlueVPN_App_Release_Manager::init();
    BlueVPN_GitHub_Updater::init();
    BlueVPN_Telegram_Bot::init();
    BlueVPN_Support::init();
    BlueVPN_Migration::init();
    BlueVPN_Production::init();
});
