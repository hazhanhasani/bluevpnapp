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
            'app_settings', 'app_releases', 'ad_assets', 'server_locations', 'pasarguard_panels',
            'marzban_panels', 'guardcore_panels', 'plans', 'customers',
            'otp_challenges', 'customer_sessions', 'customer_devices', 'sms_settings',
            'sms_templates', 'sms_deliveries', 'payment_settings', 'orders',
            'webhook_deliveries', 'bot_settings', 'bot_jobs', 'ai_connection_events', 'ai_live_connections',
            'ai_route_aggregates', 'ai_feedback',
        ];
    }

    public static function activate(): void {
        self::install_schema();
        self::seed_defaults();
        self::seed_release_channels();
        self::enforce_six_digit_otp();
        self::repair_client_types();
        update_option('bluevpn_manager_schema_version', BLUEVPN_MANAGER_SCHEMA_VERSION, false);
        update_option('bluevpn_manager_cutover_ready', '0', false);
    }

    public static function maybe_upgrade(): void {
        $installed = (string)get_option('bluevpn_manager_schema_version', '');
        if ($installed !== BLUEVPN_MANAGER_SCHEMA_VERSION) {
            self::install_schema();
            self::seed_defaults();
            self::seed_release_channels();
            self::enforce_six_digit_otp();
            self::repair_client_types();
            update_option('bluevpn_manager_schema_version', BLUEVPN_MANAGER_SCHEMA_VERSION, false);
            // 1.1.1 fixes optional UNIQUE customer sentinels. Re-open only the
            // customer convergence gate; never reset copied tables/progress.
            if (class_exists('BlueVPN_Migration')) {
                BlueVPN_Migration::resume_customer_repair_after_schema_fix();
            }
        }
    }

    private static function repair_client_types(): void {
        global $wpdb;
        $devices = self::table('customer_devices');
        $sessions = self::table('customer_sessions');
        // Browser logins are authentication sessions, not VPN device slots.
        // Repair rows created by versions that stored web-* IDs as app devices.
        $wpdb->query("UPDATE {$devices} SET client_type='web' WHERE device_id LIKE 'web-%' AND client_type<>'web'");
        $wpdb->query("UPDATE {$sessions} SET client_type='web' WHERE device_id LIKE 'web-%' AND client_type<>'web'");
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

        $queries[] = "CREATE TABLE {$t('app_releases')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            github_release_id bigint unsigned NULL,
            version varchar(32) NOT NULL DEFAULT '',
            version_code int NOT NULL DEFAULT 0,
            state varchar(20) NOT NULL DEFAULT 'beta',
            force_update tinyint(1) NOT NULL DEFAULT 0,
            title varchar(200) NOT NULL DEFAULT '',
            message longtext NULL,
            apk_url longtext NULL,
            apk_assets_json longtext NULL,
            apk_asset_meta_json longtext NULL,
            release_url longtext NULL,
            release_published_at varchar(64) NOT NULL DEFAULT '',
            build_number int NOT NULL DEFAULT 0,
            commit_sha varchar(80) NOT NULL DEFAULT '',
            fingerprint varchar(64) NOT NULL DEFAULT '',
            source varchar(80) NOT NULL DEFAULT '',
            promoted_at datetime NULL,
            stopped_at datetime NULL,
            created_at datetime NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_app_release_version (version),
            KEY ix_app_release_state_code (state, version_code),
            KEY ix_app_release_github (github_release_id),
            KEY ix_app_release_updated (updated_at)
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
            KEY ix_plan_active (active, deleted, sort_order),
            KEY ix_plan_pasarguard (panel_id, active),
            KEY ix_plan_marzban (marzban_panel_id, active),
            KEY ix_plan_guardcore (guardcore_panel_id, active)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('customers')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            email varchar(255) NULL DEFAULT NULL,
            password_hash longtext NOT NULL,
            phone varchar(20) NULL,
            phone_verified_at datetime NULL,
            auth_method varchar(24) NULL DEFAULT 'legacy_email',
            active tinyint(1) NOT NULL DEFAULT 1,
            beta_tester tinyint(1) NOT NULL DEFAULT 0,
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
            subscription_token varchar(100) NULL DEFAULT NULL,
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
            KEY ix_customer_beta_active (beta_tester, active),
            UNIQUE KEY uq_subscription_token (subscription_token),
            KEY ix_customer_plan (plan_id),
            KEY ix_customer_active (active),
            KEY ix_customer_entitlement (active, subscription_status, subscription_expire),
            KEY ix_customer_sync_due (active, last_sync_at),
            KEY ix_customer_pasarguard (panel_id, pg_user_id),
            KEY ix_customer_marzban (marzban_panel_id, marzban_user_id),
            KEY ix_customer_guardcore (guardcore_panel_id, guardcore_subscription_id)
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
            client_type varchar(16) NOT NULL DEFAULT 'app',
            expires_at datetime NULL,
            revoked_at datetime NULL,
            last_seen_at datetime NULL,
            created_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_session_token (token_hash),
            KEY ix_session_customer (customer_id),
            KEY ix_session_expiry (expires_at),
            KEY ix_session_customer_active (customer_id, revoked_at, expires_at),
            KEY ix_session_device_seen (device_id, last_seen_at),
            KEY ix_session_customer_type_active (customer_id, client_type, revoked_at, expires_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('customer_devices')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint unsigned NOT NULL,
            device_id varchar(180) NOT NULL DEFAULT '',
            device_name varchar(180) NOT NULL DEFAULT '',
            client_type varchar(16) NOT NULL DEFAULT 'app',
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
            KEY ix_device_active (active),
            KEY ix_device_customer_active_seen (customer_id, active, last_seen_at),
            KEY ix_device_refresh_expiry (refresh_expires_at),
            KEY ix_device_customer_type_active (customer_id, client_type, active, last_seen_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('sms_settings')} (
            id bigint unsigned NOT NULL,
            provider varchar(40) NOT NULL DEFAULT 'iranpayamak',
            base_url varchar(500) NOT NULL DEFAULT 'https://api.iranpayamak.com/ws/v1',
            api_key_enc longtext NULL,
            from_number varchar(32) NOT NULL DEFAULT '',
            pattern_code varchar(120) NOT NULL DEFAULT '',
            parameter_name varchar(80) NOT NULL DEFAULT 'code',
            otp_length int NOT NULL DEFAULT 6,
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
            KEY ix_order_checkout_closed (checkout_closed_at),
            KEY ix_order_customer_status_created (customer_id, status, created_at),
            KEY ix_order_status_expiry (status, expires_at),
            KEY ix_order_payment_status (payment_id, status)
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
            provider_delivery_status varchar(40) NOT NULL DEFAULT 'unknown',
            provider_delivery_at datetime NULL,
            response_json longtext NULL,
            last_error longtext NULL,
            next_attempt_at datetime NULL,
            sending_started_at datetime NULL,
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
            KEY ix_webhook_payment (payment_id),
            KEY ix_webhook_payment_event_created (payment_id, event, created_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('bot_settings')} (
            id bigint unsigned NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 0,
            bot_token_enc longtext NULL,
            admin_ids longtext NULL,
            github_token_enc longtext NULL,
            github_repository varchar(240) NOT NULL DEFAULT 'hazhanhasani/bluevpnapp',
            git_branch varchar(180) NOT NULL DEFAULT 'main',
            github_workflow varchar(180) NOT NULL DEFAULT 'build-apk.yml',
            repository_dispatch_event varchar(120) NOT NULL DEFAULT 'bluevpn_build',
            max_zip_mb int NOT NULL DEFAULT 50,
            max_extracted_mb int NOT NULL DEFAULT 900,
            max_files int NOT NULL DEFAULT 25000,
            webhook_secret varchar(180) NOT NULL DEFAULT '',
            webhook_secret_token_enc longtext NULL,
            webhook_status varchar(40) NOT NULL DEFAULT 'not_configured',
            webhook_last_error longtext NULL,
            last_update_at datetime NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('bot_jobs')} (
            id varchar(36) NOT NULL,
            chat_id varchar(32) NOT NULL DEFAULT '',
            user_id varchar(32) NOT NULL DEFAULT '',
            kind varchar(30) NOT NULL DEFAULT 'deploy_zip',
            status varchar(40) NOT NULL DEFAULT 'queued',
            telegram_file_id longtext NULL,
            telegram_file_name varchar(240) NOT NULL DEFAULT '',
            source_message_id bigint NOT NULL DEFAULT 0,
            progress_message_id bigint NOT NULL DEFAULT 0,
            commit_sha varchar(80) NOT NULL DEFAULT '',
            run_id bigint unsigned NOT NULL DEFAULT 0,
            run_url longtext NULL,
            attempts int NOT NULL DEFAULT 0,
            last_error longtext NULL,
            created_at datetime NULL,
            updated_at datetime NULL,
            finished_at datetime NULL,
            PRIMARY KEY  (id),
            KEY ix_bot_job_chat_status (chat_id, status),
            KEY ix_bot_job_status_created (status, created_at),
            KEY ix_bot_job_run (run_id)
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
            plan_tier varchar(16) NOT NULL DEFAULT 'unknown',
            ai_schema_version int NOT NULL DEFAULT 1,
            ai_client_version varchar(40) NOT NULL DEFAULT '',
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
            KEY ix_ai_event_customer_created (customer_id, created_at),
            KEY ix_ai_event_device_created (device_id, created_at),
            KEY ix_ai_event_config (config_key),
            KEY ix_ai_event_location (location_key),
            KEY ix_ai_event_context (operator, network_type, created_at),
            KEY ix_ai_event_tier_context (plan_tier, operator, network_type, created_at),
            KEY ix_ai_event_version_tier (app_version, plan_tier, created_at),
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
            plan_tier varchar(16) NOT NULL DEFAULT 'unknown',
            ai_schema_version int NOT NULL DEFAULT 1,
            ai_client_version varchar(40) NOT NULL DEFAULT '',
            connected tinyint(1) NOT NULL DEFAULT 0,
            verified tinyint(1) NOT NULL DEFAULT 0,
            tunnel_running tinyint(1) NOT NULL DEFAULT 0,
            vpn_transport tinyint(1) NOT NULL DEFAULT 0,
            verification_source varchar(80) NOT NULL DEFAULT '',
            ping_ms int NOT NULL DEFAULT 0,
            ping_min_ms int NOT NULL DEFAULT 0,
            ping_max_ms int NOT NULL DEFAULT 0,
            jitter_ms int NOT NULL DEFAULT 0,
            packet_loss_x100 int NOT NULL DEFAULT 0,
            ping_samples int NOT NULL DEFAULT 0,
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
            KEY ix_ai_live_tier (plan_tier, connected, expires_at),
            KEY ix_ai_live_version (app_version, plan_tier, last_seen_at),
            KEY ix_ai_live_seen (last_seen_at),
            KEY ix_ai_live_device_state (device_id, connected, expires_at)
        ) $cc;";

        $queries[] = "CREATE TABLE {$t('ai_route_aggregates')} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            config_key varchar(80) NOT NULL DEFAULT '',
            location_key varchar(24) NOT NULL DEFAULT 'unknown',
            location_title varchar(100) NOT NULL DEFAULT 'نامشخص',
            operator varchar(100) NOT NULL DEFAULT 'unknown',
            network_type varchar(30) NOT NULL DEFAULT 'unknown',
            mode varchar(30) NOT NULL DEFAULT 'balanced',
            plan_tier varchar(16) NOT NULL DEFAULT 'unknown',
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
            UNIQUE KEY uq_ai_route_context_tier (config_key, plan_tier, operator, network_type, mode, hour_bucket),
            KEY ix_ai_route_rank (plan_tier, operator, network_type, mode, score),
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
        self::ensure_customer_nullable_unique_columns();
        self::ensure_ai_tier_indexes();
    }


    /**
     * BlueAI v2 learns Free and Premium independently. Older installations had
     * a unique aggregate index without plan_tier; leaving that index in place
     * would silently collapse the two learning channels into one row.
     */
    public static function ensure_ai_tier_indexes(): void {
        global $wpdb;
        $table = self::table('ai_route_aggregates');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string)$exists !== $table) return;

        $legacy = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name='uq_ai_route_context'");
        if ($legacy !== null) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX uq_ai_route_context");
        }
        $current = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name='uq_ai_route_context_tier'");
        if ($current === null) {
            $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY uq_ai_route_context_tier (config_key, plan_tier, operator, network_type, mode, hour_bucket)");
        }
    }

    /**
     * Optional customer identities must be NULL, not empty strings.
     * MySQL UNIQUE indexes allow multiple NULL values but only one ''.
     * The old schema therefore collapsed phone-only / token-less customers
     * during REPLACE-based migration. This upgrade is idempotent and safe.
     */
    public static function ensure_customer_nullable_unique_columns(): void {
        global $wpdb;
        $table = self::table('customers');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string)$exists !== $table) return;

        // Convert legacy sentinels before changing/using unique indexes.
        $wpdb->query("UPDATE {$table} SET `email`=NULL WHERE `email` IS NOT NULL AND TRIM(`email`)=''");
        $wpdb->query("UPDATE {$table} SET `phone`=NULL WHERE `phone` IS NOT NULL AND TRIM(`phone`)=''");
        $wpdb->query("UPDATE {$table} SET `subscription_token`=NULL WHERE `subscription_token` IS NOT NULL AND TRIM(`subscription_token`)=''");

        // dbDelta is not reliable for NULL/DEFAULT attribute-only changes.
        // Apply them explicitly; these ALTERs are idempotent.
        $wpdb->query("ALTER TABLE {$table} MODIFY `email` varchar(255) NULL DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} MODIFY `phone` varchar(20) NULL DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} MODIFY `subscription_token` varchar(100) NULL DEFAULT NULL");
    }


    public static function enforce_six_digit_otp(): void {
        global $wpdb;
        $sms = self::table('sms_settings');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sms));
        if ((string)$exists === $sms) {
            $wpdb->update($sms, ['otp_length' => 6, 'updated_at' => BlueVPN_Utils::now_mysql()], ['id' => 1]);
        }
        $settings = self::settings();
        if (($settings['auth_mode'] ?? '') === 'email_password' || empty($settings['auth_mode'])) {
            $settings['auth_mode'] = 'phone_otp';
            self::save_settings($settings);
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
            // Legacy/global switch kept for older installs. Release-aware clients use
            // the channel-specific values below; both default to automatic delivery.
            'auto_update' => true,
            'auto_update_stable' => true,
            'auto_update_beta' => true,
            'latest_version' => '0.0.0',
            'latest_version_code' => 0,
            'apk_url' => '',
            'apk_assets' => [],
            'apk_asset_meta' => [],
            'update_title' => 'نسخه جدید BlueVPN',
            'update_message' => '',
            'release_url' => '',
            'release_published_at' => '',
            'release_build_number' => 0,
            'release_commit' => '',
            'update_source' => 'wordpress_settings',
            'github_repository' => '',
            'github_error' => '',
            'release_cache_seconds' => 15,
            'announcement_enabled' => true,
            'announcement_id' => 'wordpress-stage-1',
            'announcement_title' => 'مهاجرت BlueVPN',
            'announcement_message' => 'زیرساخت جدید BlueVPN در حال آماده‌سازی است.',
            'blueai_enabled' => true,
            'blueai_free_enabled' => true,
            'blueai_premium_enabled' => true,
            'blueai_collective' => true,
            'blueai_auto_heal' => true,
            'blueai_min_samples' => 3,
            'blueai_live_refresh_seconds' => 5,
            'blueai_privacy_message' => 'فقط شاخص‌های فنی اتصال و بدون محتوای ترافیک جمع‌آوری می‌شود.',
            'ads_enabled' => false,
            'ads_autoplay' => true,
            'ads_loop' => true,
            'ads_interval_seconds' => 6,
            'ads_height_dp' => 146,
            'ads_items' => [],
            'tapsell_enabled' => false,
            'tapsell_app_key' => '',
            'tapsell_interstitial_zone_id' => '',
            'tapsell_show_after_connect' => true,
            'tapsell_min_interval_seconds' => 0,
            'tapsell_daily_cap' => 0,
            'free_story_ads_enabled' => false,
            'free_story_ads_required' => true,
            'free_story_ads_image_seconds' => 6,
            'free_story_ads_load_timeout_seconds' => 8,
            'free_story_ads_max_video_seconds' => 30,
            'free_story_ads_items' => [],
            'free_access_enabled' => false,
            'free_subscription_url' => '',
            'free_subscription_items' => [],
            'free_session_minutes' => 60,
            'auth_mode' => 'phone_otp',
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
                'otp_length' => 6,
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

        $bot = self::table('bot_settings');
        if (!$wpdb->get_var("SELECT id FROM {$bot} WHERE id=1")) {
            $webhookSecret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $webhookToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $wpdb->insert($bot, [
                'id' => 1,
                'enabled' => 0,
                'bot_token_enc' => '',
                'admin_ids' => '',
                'github_token_enc' => '',
                'github_repository' => 'hazhanhasani/bluevpnapp',
                'git_branch' => 'main',
                'github_workflow' => 'build-apk.yml',
                'repository_dispatch_event' => 'bluevpn_build',
                'max_zip_mb' => 50,
                'max_extracted_mb' => 900,
                'max_files' => 25000,
                'webhook_secret' => $webhookSecret,
                'webhook_secret_token_enc' => BlueVPN_Utils::encrypt_secret($webhookToken),
                'webhook_status' => 'not_configured',
                'webhook_last_error' => '',
                'updated_at' => $now,
            ]);
        }
    }

    public static function seed_release_channels(): void {
        global $wpdb;
        $table = self::table('app_releases');
        $hasStable = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE state='stable'") > 0;
        if ($hasStable) return;

        $settings = self::settings();
        $version = trim((string)($settings['latest_version'] ?? ''));
        $apkUrl = trim((string)($settings['apk_url'] ?? ''));
        if ($version === '' || $version === '0.0.0' || $apkUrl === '' || !preg_match('/^\d+\.\d+\.\d+$/', $version)) return;

        $now = BlueVPN_Utils::now_mysql();
        $wpdb->replace($table, [
            'github_release_id' => null,
            'version' => $version,
            'version_code' => max(0, (int)($settings['latest_version_code'] ?? 0)),
            'state' => 'stable',
            'force_update' => !empty($settings['force_update']) ? 1 : 0,
            'title' => sanitize_text_field((string)($settings['update_title'] ?? ('BlueVPN ' . $version))),
            'message' => sanitize_textarea_field((string)($settings['update_message'] ?? '')),
            'apk_url' => esc_url_raw($apkUrl),
            'apk_assets_json' => BlueVPN_Utils::json_encode(is_array($settings['apk_assets'] ?? null) ? $settings['apk_assets'] : []),
            'apk_asset_meta_json' => BlueVPN_Utils::json_encode(is_array($settings['apk_asset_meta'] ?? null) ? $settings['apk_asset_meta'] : []),
            'release_url' => esc_url_raw((string)($settings['release_url'] ?? '')),
            'release_published_at' => sanitize_text_field((string)($settings['release_published_at'] ?? '')),
            'build_number' => max(0, (int)($settings['release_build_number'] ?? 0)),
            'commit_sha' => sanitize_text_field((string)($settings['release_commit'] ?? '')),
            'fingerprint' => '',
            'source' => 'schema_upgrade_stable_seed',
            'promoted_at' => $now,
            'stopped_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
