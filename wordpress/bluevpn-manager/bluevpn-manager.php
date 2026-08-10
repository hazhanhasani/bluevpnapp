<?php
/**
 * Plugin Name: BlueVPN Manager
 * Plugin URI: https://bluevpn.local/
 * Description: Backend/API foundation for migrating BlueVPN from Railway/PostgreSQL to WordPress/MySQL.
 * Version: 1.2.1
 * Author: BlueVPN
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Text Domain: bluevpn-manager
 * Update URI: https://github.com/hazhanhasani/bluevpnapp
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BLUEVPN_MANAGER_VERSION', '1.2.1');
define('BLUEVPN_MANAGER_SCHEMA_VERSION', '1.1.0');
define('BLUEVPN_MANAGER_FILE', __FILE__);
define('BLUEVPN_MANAGER_DIR', plugin_dir_path(__FILE__));
define('BLUEVPN_MANAGER_URL', plugin_dir_url(__FILE__));

require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-utils.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-db.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-auth.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-api.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-compat.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-cron.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-admin.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-github-updater.php';
require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-migration.php';

register_activation_hook(__FILE__, function () {
    BlueVPN_DB::activate();
    BlueVPN_Compat::register_rewrites();
    flush_rewrite_rules(false);
    BlueVPN_Cron::schedule();
    BlueVPN_Migration::sync_cron_schedule(!empty(BlueVPN_Migration::settings()['auto_sync']));
});

register_deactivation_hook(__FILE__, function () {
    BlueVPN_Cron::unschedule();
    BlueVPN_Migration::sync_cron_schedule(false);
    flush_rewrite_rules(false);
});

add_action('plugins_loaded', function () {
    BlueVPN_DB::maybe_upgrade();
    BlueVPN_API::init();
    BlueVPN_Compat::init();
    BlueVPN_Cron::init();
    BlueVPN_Admin::init();
    BlueVPN_GitHub_Updater::init();
    BlueVPN_Migration::init();
});
