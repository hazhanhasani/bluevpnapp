<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BlueVPN_DB {
    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'bluevpn_' . $name;
    }

    public static function table_names(): array {
        return [
            'app_settings', 'ad_assets', 'server_locations', 'pasarguard_panels',
            'marzban_panels', 'guardcore_panels', 'plans', 'customers',
            'otp_challenges', 'customer_sessions', 'customer_devices', 'sms_settings',
            'sms_templates', 'sms_deliveries', 'payment_settings', 'orders',
            'webhook_deliveries', 'ai_connection_events', 'ai_live_connections',
            'ai_route_aggregates', 'ai_feedback',
        ];
    }

    public static function activate(): void {
        self::install_schema();
        self::seed_defaults();
        update_option('bluevpn_manager_schema_version', BLUEVPN_MANAGER_SCHEMA_VERSION, false);
        update_option('bluevpn_manager_cutover_ready', '0', false);
    }

    public static function maybe_upgrade(): void {
        $installed = (string)get_option('bluevpn_manager_schema_version', '');
        if ($installed !== BLUEVPN_MANAGER_SCHEMA_VERSION) {
            self::install_schema();
            self::seed_defaults();
            update_option('bluevpn_manager_schema_version', BLUEVPN_MANAGER_SCHEMA_VERSION, false);
        }
    }

    public static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();
        $t = fn(string $name): string => self::table($name);

        $queries = [];
        $queries[] = "CREATE TABLE {$t('app_settings')} (
            id bigint unsigned NOT NULL,
            payload longtext NOT NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('ad_assets')} (
            id varchar(64) NOT NULL,
            filename varchar(180) NOT NULL DEFAULT '',
            content_type varchar(80) NOT NULL DEFAULT 'image/webp',
            payload longblob NULL,
            sha256 varchar(64) NOT NULL DEFAULT '',
            byte_size bigint unsigned NOT NULL DEFAULT 0,
            created_at datetime NULL,
            PRIMARY KEY  (id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('server_locations')} (
            config_key varchar(64) NOT NULL,
            country_code varchar(2) NOT NULL DEFAULT '',
            source varchar(40) NOT NULL DEFAULT 'client_trace',
            confidence int NOT NULL DEFAULT 100,
            verified_at datetime NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (config_key),
            KEY ix_country_code (country_code)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('pasarguard_panels')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL DEFAULT '',
            base_url varchar(500) NOT NULL DEFAULT '',
            auth_mode varchar(20) NOT NULL DEFAULT 'api_key',
            api_key_enc longtext NULL,
            username_enc longtext NULL,
            password_enc longtext NULL,
            proxy_settings_json longtext NULL,
            verify_tls tinyint(1) NOT NULL DEFAULT 1,
            active tinyint(1) NOT NULL DEFAULT 1,
            last_test_ok tinyint(1) NOT NULL DEFAULT 0,
            last_test_message longtext NULL,
            last_test_at datetime NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('marzban_panels')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL DEFAULT '',
            base_url varchar(500) NOT NULL DEFAULT '',
            username_enc longtext NULL,
            password_enc longtext NULL,
            proxies_json longtext NULL,
            inbounds_json longtext NULL,
            verify_tls tinyint(1) NOT NULL DEFAULT 1,
            active tinyint(1) NOT NULL DEFAULT 1,
            last_test_ok tinyint(1) NOT NULL DEFAULT 0,
            last_test_message longtext NULL,
            last_test_at datetime NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('guardcore_panels')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL DEFAULT '',
            base_url varchar(500) NOT NULL DEFAULT '',
            global_subscription_url longtext NULL,
            auth_mode varchar(20) NOT NULL DEFAULT 'manual',
            api_key_enc longtext NULL,
            username_enc longtext NULL,
            password_enc longtext NULL,
            usage_unit varchar(20) NOT NULL DEFAULT 'bytes',
            expire_mode varchar(20) NOT NULL DEFAULT 'days',
            services_json longtext NULL,
            verify_tls tinyint(1) NOT NULL DEFAULT 1,
            active tinyint(1) NOT NULL DEFAULT 1,
            last_test_ok tinyint(1) NOT NULL DEFAULT 0,
            last_test_message longtext NULL,
            last_test_at datetime NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('plans')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            title varchar(150) NOT NULL DEFAULT '',
            description longtext NULL,
            price_toman bigint NOT NULL DEFAULT 0,
            duration_days int NOT NULL DEFAULT 0,
            data_limit_gb int NOT NULL DEFAULT 0,
            device_limit int NOT NULL DEFAULT 1,
            group_ids_json longtext NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            deleted tinyint(1) NOT NULL DEFAULT 0,
            deleted_at datetime NULL,
            sort_order int NOT NULL DEFAULT 0,
            panel_id bigint unsigned NULL,
            marzban_panel_id bigint unsigned NULL,
            marzban_quota_mode varchar(20) NOT NULL DEFAULT 'split',
            guardcore_panel_id bigint unsigned NULL,
            guardcore_service_ids_json longtext NULL,
            multi_provider_quota_mode varchar(20) NOT NULL DEFAULT 'split',
            created_at datetime NULL,
            PRIMARY KEY  (id),
            KEY ix_plan_active (active, deleted, sort_order)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('customers')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL DEFAULT '',
            password_hash longtext NOT NULL,
            phone varchar(20) NULL,
            phone_verified_at datetime NULL,
            auth_method varchar(24) NULL DEFAULT 'legacy_email',
            active tinyint(1) NOT NULL DEFAULT 1,
            plan_id bigint unsigned NULL,
            panel_id bigint unsigned NULL,
            pg_username varchar(64) NOT NULL DEFAULT '',
            pg_user_id bigint NULL,
            pasarguard_subscription_url longtext NULL,
            marzban_panel_id bigint unsigned NULL,
            marzban_username varchar(64) NOT NULL DEFAULT '',
            marzban_user_id bigint NULL,
            marzban_subscription_url longtext NULL,
            marzban_status varchar(40) NOT NULL DEFAULT 'inactive',
            marzban_expire datetime NULL,
            marzban_data_limit_bytes bigint unsigned NOT NULL DEFAULT 0,
            marzban_used_traffic_bytes bigint unsigned NOT NULL DEFAULT 0,
            marzban_last_error longtext NULL,
            guardcore_panel_id bigint unsigned NULL,
            guardcore_username varchar(64) NOT NULL DEFAULT '',
            guardcore_subscription_id bigint NULL,
            guardcore_subscription_url longtext NULL,
            guardcore_status varchar(40) NOT NULL DEFAULT 'inactive',
            guardcore_expire datetime NULL,
            guardcore_data_limit_bytes bigint unsigned NOT NULL DEFAULT 0,
            guardcore_used_traffic_bytes bigint unsigned NOT NULL DEFAULT 0,
            guardcore_last_error longtext NULL,
            subscription_token varchar(100) NOT NULL DEFAULT '',
            subscription_url longtext NULL,
            subscription_status varchar(40) NOT NULL DEFAULT 'inactive',
            subscription_expire datetime NULL,
            data_limit_bytes bigint unsigned NOT NULL DEFAULT 0,
            used_traffic_bytes bigint unsigned NOT NULL DEFAULT 0,
            device_limit int NOT NULL DEFAULT 1,
            last_sync_at datetime NULL,
            last_sync_error longtext NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_customer_email (email(191)),
            UNIQUE KEY uq_customer_phone (phone),
            UNIQUE KEY uq_subscription_token (subscription_token),
            KEY ix_customer_plan (plan_id),
            KEY ix_customer_active (active)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('otp_challenges')} (
            id varchar(36) NOT NULL,
            phone varchar(20) NOT NULL DEFAULT '',
            purpose varchar(24) NOT NULL DEFAULT 'auth',
            customer_id bigint unsigned NULL,
            device_id varchar(180) NOT NULL DEFAULT '',
            code_hash longtext NOT NULL,
            attempts int NOT NULL DEFAULT 0,
            max_attempts int NOT NULL DEFAULT 5,
            expires_at datetime NULL,
            consumed_at datetime NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id),
            KEY ix_otp_phone (phone),
            KEY ix_otp_purpose (purpose),
            KEY ix_otp_customer (customer_id),
            KEY ix_otp_expires (expires_at),
            KEY ix_otp_phone_purpose_created (phone, purpose, created_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('customer_sessions')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint unsigned NOT NULL,
            token_hash varchar(64) NOT NULL DEFAULT '',
            device_id varchar(180) NOT NULL DEFAULT '',
            expires_at datetime NULL,
            revoked_at datetime NULL,
            last_seen_at datetime NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_session_token (token_hash),
            KEY ix_session_customer (customer_id),
            KEY ix_session_expiry (expires_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('customer_devices')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint unsigned NOT NULL,
            device_id varchar(180) NOT NULL DEFAULT '',
            device_name varchar(180) NOT NULL DEFAULT '',
            active tinyint(1) NOT NULL DEFAULT 1,
            refresh_token_hash varchar(64) NOT NULL DEFAULT '',
            refresh_expires_at datetime NULL,
            previous_refresh_token_hash varchar(64) NOT NULL DEFAULT '',
            previous_refresh_expires_at datetime NULL,
            first_seen_at datetime NULL,
            last_seen_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_customer_device (customer_id, device_id),
            KEY ix_device_customer (customer_id),
            KEY ix_device_active (active)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('sms_settings')} (
            id bigint unsigned NOT NULL,
            provider varchar(40) NOT NULL DEFAULT 'iranpayamak',
            base_url varchar(500) NOT NULL DEFAULT 'https://api.iranpayamak.com/ws/v1',
            api_key_enc longtext NULL,
            from_number varchar(32) NOT NULL DEFAULT '',
            pattern_code varchar(120) NOT NULL DEFAULT '',
            parameter_name varchar(80) NOT NULL DEFAULT 'code',
            otp_length int NOT NULL DEFAULT 5,
            otp_ttl_seconds int NOT NULL DEFAULT 120,
            resend_seconds int NOT NULL DEFAULT 60,
            active tinyint(1) NOT NULL DEFAULT 0,
            notification_active tinyint(1) NOT NULL DEFAULT 0,
            reminder_days_json longtext NULL,
            low_volume_threshold_gb int NOT NULL DEFAULT 5,
            retry_max_attempts int NOT NULL DEFAULT 3,
            verify_tls tinyint(1) NOT NULL DEFAULT 1,
            last_test_ok tinyint(1) NOT NULL DEFAULT 0,
            last_test_message longtext NULL,
            last_test_at datetime NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('sms_templates')} (
            `key` varchar(80) NOT NULL,
            title varchar(160) NOT NULL DEFAULT '',
            category varchar(80) NOT NULL DEFAULT '',
            body longtext NULL,
            variables_json longtext NULL,
            pattern_code varchar(160) NOT NULL DEFAULT '',
            enabled tinyint(1) NOT NULL DEFAULT 0,
            broadcast tinyint(1) NOT NULL DEFAULT 0,
            updated_at datetime NULL,
            PRIMARY KEY  (`key`)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('payment_settings')} (
            id bigint unsigned NOT NULL,
            base_url varchar(500) NOT NULL DEFAULT 'https://bluepay-production.up.railway.app',
            api_key_enc longtext NULL,
            callback_secret_enc longtext NULL,
            fee_mode varchar(30) NOT NULL DEFAULT 'default',
            ttl_minutes int NOT NULL DEFAULT 30,
            active tinyint(1) NOT NULL DEFAULT 0,
            updated_at datetime NULL,
            PRIMARY KEY  (id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('orders')} (
            id varchar(36) NOT NULL,
            order_code varchar(100) NOT NULL DEFAULT '',
            customer_id bigint unsigned NULL,
            plan_id bigint unsigned NULL,
            amount_toman bigint NOT NULL DEFAULT 0,
            payment_id varchar(180) NOT NULL DEFAULT '',
            payment_url longtext NULL,
            status varchar(40) NOT NULL DEFAULT 'created',
            gateway_json longtext NULL,
            activation_error longtext NULL,
            paid_at datetime NULL,
            activated_at datetime NULL,
            expires_at datetime NULL,
            checkout_opened_at datetime NULL,
            checkout_last_seen_at datetime NULL,
            checkout_closed_at datetime NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_order_code (order_code),
            KEY ix_order_customer (customer_id),
            KEY ix_order_plan (plan_id),
            KEY ix_order_payment (payment_id),
            KEY ix_order_status (status),
            KEY ix_order_expires (expires_at),
            KEY ix_order_checkout_closed (checkout_closed_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('sms_deliveries')} (
            id varchar(36) NOT NULL,
            event_key varchar(80) NOT NULL DEFAULT '',
            customer_id bigint unsigned NULL,
            order_id varchar(36) NULL,
            phone varchar(20) NOT NULL DEFAULT '',
            params_json longtext NULL,
            dedupe_key varchar(200) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT 'pending',
            attempts int NOT NULL DEFAULT 0,
            max_attempts int NOT NULL DEFAULT 3,
            provider_message_id varchar(180) NOT NULL DEFAULT '',
            response_json longtext NULL,
            last_error longtext NULL,
            next_attempt_at datetime NULL,
            sent_at datetime NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_sms_dedupe (dedupe_key),
            KEY ix_sms_event (event_key),
            KEY ix_sms_customer (customer_id),
            KEY ix_sms_order (order_id),
            KEY ix_sms_phone (phone),
            KEY ix_sms_status_next (status, next_attempt_at),
            KEY ix_sms_customer_created (customer_id, created_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('webhook_deliveries')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            delivery_id varchar(180) NOT NULL DEFAULT '',
            payment_id varchar(180) NOT NULL DEFAULT '',
            event varchar(80) NOT NULL DEFAULT '',
            created_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_delivery_id (delivery_id),
            KEY ix_webhook_payment (payment_id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('ai_connection_events')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint unsigned NULL,
            device_id varchar(80) NOT NULL DEFAULT '',
            config_key varchar(80) NOT NULL DEFAULT '',
            location_key varchar(24) NOT NULL DEFAULT 'unknown',
            location_title varchar(100) NOT NULL DEFAULT 'نامشخص',
            operator varchar(100) NOT NULL DEFAULT 'unknown',
            network_type varchar(30) NOT NULL DEFAULT 'unknown',
            mode varchar(30) NOT NULL DEFAULT 'balanced',
            event_type varchar(30) NOT NULL DEFAULT 'session',
            success tinyint(1) NOT NULL DEFAULT 0,
            ping_ms int NOT NULL DEFAULT 0,
            jitter_ms int NOT NULL DEFAULT 0,
            packet_loss_x100 int NOT NULL DEFAULT 0,
            duration_seconds int NOT NULL DEFAULT 0,
            health_score int NOT NULL DEFAULT 0,
            download_bytes bigint unsigned NOT NULL DEFAULT 0,
            upload_bytes bigint unsigned NOT NULL DEFAULT 0,
            failure_reason longtext NULL,
            app_version varchar(40) NOT NULL DEFAULT '',
            android_version varchar(40) NOT NULL DEFAULT '',
            device_model varchar(160) NOT NULL DEFAULT '',
            hour_bucket int NOT NULL DEFAULT 0,
            created_at datetime NULL,
            PRIMARY KEY  (id),
            KEY ix_ai_event_customer (customer_id),
            KEY ix_ai_event_device (device_id),
            KEY ix_ai_event_config (config_key),
            KEY ix_ai_event_location (location_key),
            KEY ix_ai_event_context (operator, network_type, created_at),
            KEY ix_ai_event_route (config_key, created_at),
            KEY ix_ai_event_success (success),
            KEY ix_ai_event_hour (hour_bucket),
            KEY ix_ai_event_created (created_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('ai_live_connections')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint unsigned NULL,
            device_id varchar(80) NOT NULL DEFAULT '',
            session_id varchar(80) NOT NULL DEFAULT '',
            config_key varchar(80) NOT NULL DEFAULT '',
            location_key varchar(24) NOT NULL DEFAULT 'unknown',
            location_title varchar(100) NOT NULL DEFAULT 'نامشخص',
            operator varchar(100) NOT NULL DEFAULT 'unknown',
            network_type varchar(30) NOT NULL DEFAULT 'unknown',
            mode varchar(30) NOT NULL DEFAULT 'balanced',
            connected tinyint(1) NOT NULL DEFAULT 0,
            verified tinyint(1) NOT NULL DEFAULT 0,
            tunnel_running tinyint(1) NOT NULL DEFAULT 0,
            vpn_transport tinyint(1) NOT NULL DEFAULT 0,
            verification_source varchar(80) NOT NULL DEFAULT '',
            ping_ms int NOT NULL DEFAULT 0,
            health_score int NOT NULL DEFAULT 0,
            download_bytes bigint unsigned NOT NULL DEFAULT 0,
            upload_bytes bigint unsigned NOT NULL DEFAULT 0,
            traffic_active tinyint(1) NOT NULL DEFAULT 0,
            last_traffic_at datetime NULL,
            heartbeat_seq bigint unsigned NOT NULL DEFAULT 0,
            started_at datetime NULL,
            last_verified_at datetime NULL,
            last_seen_at datetime NULL,
            expires_at datetime NULL,
            disconnected_at datetime NULL,
            disconnect_reason longtext NULL,
            app_version varchar(40) NOT NULL DEFAULT '',
            android_version varchar(40) NOT NULL DEFAULT '',
            device_model varchar(160) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY uq_ai_live_customer_device (customer_id, device_id),
            KEY ix_ai_live_session (session_id),
            KEY ix_ai_live_config (config_key),
            KEY ix_ai_live_verified_expiry (connected, verified, expires_at),
            KEY ix_ai_live_operator (operator, expires_at),
            KEY ix_ai_live_seen (last_seen_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('ai_route_aggregates')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            config_key varchar(80) NOT NULL DEFAULT '',
            location_key varchar(24) NOT NULL DEFAULT 'unknown',
            location_title varchar(100) NOT NULL DEFAULT 'نامشخص',
            operator varchar(100) NOT NULL DEFAULT 'unknown',
            network_type varchar(30) NOT NULL DEFAULT 'unknown',
            mode varchar(30) NOT NULL DEFAULT 'balanced',
            hour_bucket int NOT NULL DEFAULT 0,
            sample_count int NOT NULL DEFAULT 0,
            success_count int NOT NULL DEFAULT 0,
            failure_count int NOT NULL DEFAULT 0,
            total_duration_seconds bigint unsigned NOT NULL DEFAULT 0,
            total_ping_ms bigint unsigned NOT NULL DEFAULT 0,
            ping_samples int NOT NULL DEFAULT 0,
            total_jitter_ms bigint unsigned NOT NULL DEFAULT 0,
            jitter_samples int NOT NULL DEFAULT 0,
            total_packet_loss_x100 bigint unsigned NOT NULL DEFAULT 0,
            score int NOT NULL DEFAULT 50,
            recent_score int NOT NULL DEFAULT 50,
            confidence_score double NOT NULL DEFAULT 0,
            recent_success_rate double NOT NULL DEFAULT 0,
            adaptive_sample_weight double NOT NULL DEFAULT 0,
            consecutive_failures int NOT NULL DEFAULT 0,
            last_success_at datetime NULL,
            last_failure_at datetime NULL,
            success_rate double NOT NULL DEFAULT 0,
            average_ping_ms double NOT NULL DEFAULT 0,
            average_duration_seconds double NOT NULL DEFAULT 0,
            updated_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_ai_route_context (config_key, operator, network_type, mode, hour_bucket),
            KEY ix_ai_route_rank (operator, network_type, mode, score),
            KEY ix_ai_route_live_rank (operator, network_type, recent_score, updated_at),
            KEY ix_ai_route_location (location_key),
            KEY ix_ai_route_updated (updated_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('ai_feedback')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint unsigned NULL,
            rating int NOT NULL DEFAULT 5,
            category varchar(50) NOT NULL DEFAULT 'general',
            message longtext NULL,
            diagnostics_json longtext NULL,
            app_version varchar(40) NOT NULL DEFAULT '',
            created_at datetime NULL,
            PRIMARY KEY  (id),
            KEY ix_feedback_customer (customer_id),
            KEY ix_feedback_created (created_at)
        ) $cc;";

        foreach ($queries as $sql) {
            dbDelta($sql);
        }
    }

    public static function default_settings(): array {
        return [
            'app_name' => 'BlueVPN',
            'public_base_url' => untrailingslashit(home_url('/')),
            'maintenance' => false,
            'support_url' => '',
            'minimum_version' => '0.4.9',
            'force_update' => false,
            'auto_update' => true,
            'latest_version' => '0.0.0',
            'latest_version_code' => 0,
            'apk_url' => '',
            'update_title' => 'نسخه جدید BlueVPN',
            'update_message' => '',
            'announcement_enabled' => true,
            'announcement_id' => 'wordpress-stage-1',
            'announcement_title' => 'مهاجرت BlueVPN',
            'announcement_message' => 'زیرساخت جدید BlueVPN در حال آماده‌سازی است.',
            'blueai_enabled' => false,
            'blueai_collective' => false,
            'blueai_auto_heal' => false,
            'blueai_min_samples' => 3,
            'blueai_privacy_message' => 'فقط شاخص‌های فنی اتصال و بدون محتوای ترافیک جمع‌آوری می‌شود.',
            'ads_enabled' => false,
            'ads_autoplay' => true,
            'ads_loop' => true,
            'ads_interval_seconds' => 6,
            'ads_height_dp' => 146,
            'ads_items' => [],
            'auth_mode' => 'email_password',
            'updated_at' => BlueVPN_Utils::iso_now(),
        ];
    }

    public static function seed_defaults(): void {
        global $wpdb;
        $now = BlueVPN_Utils::now_mysql();

        $settingsTable = self::table('app_settings');
        $exists = $wpdb->get_var("SELECT id FROM {$settingsTable} WHERE id=1");
        if (!$exists) {
            $wpdb->insert($settingsTable, [
                'id' => 1,
                'payload' => BlueVPN_Utils::json_encode(self::default_settings()),
                'updated_at' => $now,
            ], ['%d', '%s', '%s']);
        }

        $sms = self::table('sms_settings');
        if (!$wpdb->get_var("SELECT id FROM {$sms} WHERE id=1")) {
            $wpdb->insert($sms, [
                'id' => 1,
                'provider' => 'iranpayamak',
                'base_url' => 'https://api.iranpayamak.com/ws/v1',
                'api_key_enc' => '',
                'from_number' => '',
                'pattern_code' => '',
                'parameter_name' => 'code',
                'otp_length' => 5,
                'otp_ttl_seconds' => 120,
                'resend_seconds' => 60,
                'active' => 0,
                'notification_active' => 0,
                'reminder_days_json' => '[3,2,1]',
                'low_volume_threshold_gb' => 5,
                'retry_max_attempts' => 3,
                'verify_tls' => 1,
                'last_test_ok' => 0,
                'last_test_message' => '',
                'updated_at' => $now,
            ]);
        }

        $payment = self::table('payment_settings');
        if (!$wpdb->get_var("SELECT id FROM {$payment} WHERE id=1")) {
            $wpdb->insert($payment, [
                'id' => 1,
                'base_url' => 'https://bluepay-production.up.railway.app',
                'api_key_enc' => '',
                'callback_secret_enc' => '',
                'fee_mode' => 'default',
                'ttl_minutes' => 30,
                'active' => 0,
                'updated_at' => $now,
            ]);
        }
    }

    public static function settings(): array {
        global $wpdb;
        $table = self::table('app_settings');
        $payload = $wpdb->get_var("SELECT payload FROM {$table} WHERE id=1");
        $saved = BlueVPN_Utils::json_decode_array(is_string($payload) ? $payload : '', []);
        return array_replace_recursive(self::default_settings(), $saved);
    }

    public static function save_settings(array $settings): bool {
        global $wpdb;
        $settings = array_replace_recursive(self::default_settings(), $settings);
        $settings['updated_at'] = BlueVPN_Utils::iso_now();
        $table = self::table('app_settings');
        $result = $wpdb->replace($table, [
            'id' => 1,
            'payload' => BlueVPN_Utils::json_encode($settings),
            'updated_at' => BlueVPN_Utils::now_mysql(),
        ], ['%d', '%s', '%s']);
        return $result !== false;
    }

    public static function status(): array {
        global $wpdb;
        $ready = true;
        $missing = [];
        foreach (self::table_names() as $name) {
            $table = self::table($name);
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found !== $table) {
                $ready = false;
                $missing[] = $name;
            }
        }
        return [
            'ready' => $ready,
            'mode' => 'mysql',
            'driver' => 'wpdb',
            'schema_version' => (string)get_option('bluevpn_manager_schema_version', ''),
            'table_count_expected' => count(self::table_names()),
            'missing_tables' => $missing,
            'mysql_version' => (string)$wpdb->db_version(),
            'prefix' => $wpdb->prefix . 'bluevpn_',
        ];
    }

    public static function counts(): array {
        global $wpdb;
        $result = [];
        foreach (self::table_names() as $name) {
            $table = self::table($name);
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            $result[$name] = ($found === $table) ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}") : -1;
        }
        return $result;
    }
}
