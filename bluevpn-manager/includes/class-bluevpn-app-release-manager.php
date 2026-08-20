<?php
if (!defined('ABSPATH')) exit;

/**
 * Android release channels for BlueVPN.
 *
 * GitHub only builds binaries. WordPress/MySQL decides who can see each build:
 * - every newly discovered GitHub release enters Beta
 * - only customers flagged beta_tester can receive Beta
 * - normal users stay on the latest Stable release
 * - promoting Beta -> Stable never rebuilds the APK
 */
final class BlueVPN_App_Release_Manager {
    private const OPTION = 'bluevpn_app_release_manager_settings_v1';
    private const STATUS_OPTION = 'bluevpn_app_release_manager_status_v1';
    private const LAST_SYNC_OPTION = 'bluevpn_app_release_last_sync_v1';
    private const CRON_HOOK = 'bluevpn_app_release_fallback_sync';
    private const KICK_LOCK = 'bluevpn_app_release_kick_lock_v1';
    private const SYNC_LOCK = 'bluevpn_app_release_sync_lock_v1';
    private const DEFAULT_OWNER = 'hazhanhasani';
    private const DEFAULT_REPO = 'bluevpnapp';
    private const VALID_STATES = ['beta','stable','stopped','archived'];

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'background_sync']);
        add_action('admin_init', [self::class, 'ensure_schedule']);
        add_action('init', [self::class, 'maybe_kick'], 21);
        self::ensure_schedule();
    }

    public static function defaults(): array {
        return [
            'owner' => self::DEFAULT_OWNER,
            'repo' => self::DEFAULT_REPO,
            'auto_sync' => true,
            'title_override' => '',
            'message_override' => '',
        ];
    }

    public static function settings(): array {
        $saved = get_option(self::OPTION, []);
        return wp_parse_args(is_array($saved) ? $saved : [], self::defaults());
    }

    public static function save_settings(array $settings): void {
        $clean = self::defaults();
        $clean['owner'] = self::clean_slug((string)($settings['owner'] ?? self::DEFAULT_OWNER));
        $clean['repo'] = self::clean_slug((string)($settings['repo'] ?? self::DEFAULT_REPO));
        $clean['auto_sync'] = !empty($settings['auto_sync']);
        $clean['title_override'] = sanitize_text_field((string)($settings['title_override'] ?? ''));
        $clean['message_override'] = sanitize_textarea_field((string)($settings['message_override'] ?? ''));
        if ($clean['owner'] === '') $clean['owner'] = self::DEFAULT_OWNER;
        if ($clean['repo'] === '') $clean['repo'] = self::DEFAULT_REPO;
        update_option(self::OPTION, $clean, false);
    }

    private static function clean_slug(string $value): string {
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $value) ?: '';
    }

    public static function repository(): string {
        $s = self::settings();
        return $s['owner'] . '/' . $s['repo'];
    }

    public static function repository_url(): string {
        $s = self::settings();
        return 'https://github.com/' . rawurlencode($s['owner']) . '/' . rawurlencode($s['repo']);
    }

    public static function status(): array {
        $value = get_option(self::STATUS_OPTION, []);
        return is_array($value) ? wp_parse_args($value, [
            'status' => 'never', 'message' => '', 'version' => '', 'version_code' => 0,
            'release_url' => '', 'source' => '', 'at' => 0,
        ]) : [
            'status' => 'never', 'message' => '', 'version' => '', 'version_code' => 0,
            'release_url' => '', 'source' => '', 'at' => 0,
        ];
    }

    private static function save_status(string $status, string $message, array $extra = []): void {
        update_option(self::STATUS_OPTION, array_merge([
            'status' => sanitize_key($status),
            'message' => sanitize_text_field($message),
            'version' => '',
            'version_code' => 0,
            'release_url' => '',
            'source' => '',
            'at' => time(),
        ], $extra), false);
    }

    public static function last_sync(): int { return (int)get_option(self::LAST_SYNC_OPTION, 0); }

    public static function ensure_schedule(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;
        if ($event && (string)($event->schedule ?? '') !== 'bluevpn_ten_minutes') self::unschedule();
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + 90, 'bluevpn_ten_minutes', self::CRON_HOOK);
    }

    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules['bluevpn_ten_minutes'])) {
            $schedules['bluevpn_ten_minutes'] = ['interval' => 10 * MINUTE_IN_SECONDS, 'display' => 'BlueVPN every 10 minutes'];
        }
        return $schedules;
    }

    public static function unschedule(): void {
        while ($timestamp = wp_next_scheduled(self::CRON_HOOK)) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }

    public static function maybe_kick(bool $force = false): void {
        if (empty(self::settings()['auto_sync'])) return;
        $last = self::last_sync();
        if (!$force && $last > 0 && (time() - $last) < 10 * MINUTE_IN_SECONDS) return;
        if (get_transient(self::KICK_LOCK)) return;
        set_transient(self::KICK_LOCK, '1', 60);
        wp_schedule_single_event(time() + 1, self::CRON_HOOK, ['traffic-kick']);
        $cron_url = site_url('/wp-cron.php?doing_wp_cron=' . rawurlencode(sprintf('%.22F', microtime(true))));
        wp_remote_post($cron_url, ['timeout' => 0.01, 'blocking' => false, 'sslverify' => apply_filters('https_local_ssl_verify', true)]);
    }

    public static function background_sync($reason = null): void {
        try { if (!empty(self::settings()['auto_sync'])) self::sync_now(false, 'wordpress_background'); }
        finally { delete_transient(self::KICK_LOCK); }
    }

    public static function sync_now(bool $force = true, string $source = 'manual'): array {
        if (get_transient(self::SYNC_LOCK)) return ['ok' => true, 'message' => 'همگام‌سازی دیگری در حال اجرا است.', 'status' => self::status()];
        set_transient(self::SYNC_LOCK, '1', 90);
        try {
            $response = self::fetch_releases();
            if (is_wp_error($response)) {
                self::save_status('error', $response->get_error_message(), ['source' => $source]);
                return ['ok' => false, 'message' => $response->get_error_message(), 'status' => self::status()];
            }
            return self::ingest_releases($response, $source, $force);
        } finally { delete_transient(self::SYNC_LOCK); }
    }

    private static function fetch_releases() {
        $s = self::settings();
        $url = 'https://api.github.com/repos/' . rawurlencode($s['owner']) . '/' . rawurlencode($s['repo']) . '/releases?per_page=30';
        $response = wp_remote_get($url, ['timeout' => 12, 'redirection' => 3, 'headers' => self::request_headers()]);
        if (is_wp_error($response)) return $response;
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code !== 200) return new WP_Error('bluevpn_app_release_http', 'GitHub API HTTP ' . $code);
        $releases = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($releases) ? $releases : new WP_Error('bluevpn_app_release_json', 'پاسخ Releaseهای GitHub معتبر نیست.');
    }

    private static function request_headers(): array {
        return [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'BlueVPN-App-Release-Manager/' . BLUEVPN_MANAGER_VERSION . '; ' . home_url('/'),
        ];
    }

    public static function ingest_releases(array $releases, string $source = 'shared_github_poll', bool $force = false): array {
        if (!$force && empty(self::settings()['auto_sync'])) return ['ok' => true, 'message' => 'همگام‌سازی خودکار اپ غیرفعال است.', 'status' => self::status()];
        update_option(self::LAST_SYNC_OPTION, time(), false);

        $candidates = [];
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) continue;
            $tag = trim((string)($release['tag_name'] ?? ''));
            if (!preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $m)) continue;
            $release['_bluevpn_version'] = $m[1];
            $candidates[] = $release;
        }
        if (!$candidates) {
            self::save_status('no_release', 'Release اپلیکیشن با Tag استاندارد vX.Y.Z پیدا نشد.', ['source' => $source]);
            return ['ok' => false, 'message' => 'Release اپلیکیشن پیدا نشد.', 'status' => self::status()];
        }
        usort($candidates, static fn($a, $b) => version_compare((string)$b['_bluevpn_version'], (string)$a['_bluevpn_version']));

        global $wpdb;
        $table = BlueVPN_DB::table('app_releases');
        $imported = 0; $updated = 0; $skipped = 0; $failed = 0; $top = null;
        foreach ($candidates as $release) {
            $version = (string)$release['_bluevpn_version'];
            $fingerprint = self::release_fingerprint($release);
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE version=%s LIMIT 1", $version), ARRAY_A);
            if ($existing && $fingerprint !== '' && hash_equals((string)($existing['fingerprint'] ?? ''), $fingerprint)) { $skipped++; continue; }
            if ($existing && (string)($existing['state'] ?? '') === 'stable' && (string)($existing['fingerprint'] ?? '') !== '' && $fingerprint !== '' && !hash_equals((string)$existing['fingerprint'], $fingerprint)) {
                // A promoted Stable binary is immutable. Rebuilding the same semantic
                // version must never replace a public APK behind the administrator's back.
                $failed++;
                continue;
            }

            $meta = self::parse_release($release);
            if (is_wp_error($meta)) { $failed++; continue; }
            $state = $existing && in_array((string)$existing['state'], self::VALID_STATES, true) ? (string)$existing['state'] : 'beta';
            $forceUpdate = $existing ? (int)$existing['force_update'] : 0;
            $now = BlueVPN_Utils::now_mysql();
            $row = [
                'github_release_id' => max(0, (int)($release['id'] ?? 0)) ?: null,
                'version' => $version,
                'version_code' => (int)$meta['version_code'],
                'state' => $state,
                'force_update' => $forceUpdate,
                'title' => (string)$meta['title'],
                'message' => (string)$meta['message'],
                'apk_url' => (string)$meta['apk_url'],
                'apk_assets_json' => BlueVPN_Utils::json_encode($meta['apk_assets']),
                'apk_asset_meta_json' => BlueVPN_Utils::json_encode($meta['apk_asset_meta']),
                'release_url' => (string)$meta['release_url'],
                'release_published_at' => (string)$meta['release_published_at'],
                'build_number' => (int)$meta['build_number'],
                'commit_sha' => (string)$meta['commit'],
                'fingerprint' => $fingerprint,
                'source' => sanitize_key($source),
                'promoted_at' => $existing['promoted_at'] ?? null,
                'stopped_at' => $existing['stopped_at'] ?? null,
                'created_at' => $existing['created_at'] ?? $now,
                'updated_at' => $now,
            ];
            if ($existing) {
                $ok = $wpdb->update($table, $row, ['id' => (int)$existing['id']]);
                if ($ok !== false) $updated++; else $failed++;
            } else {
                $ok = $wpdb->insert($table, $row);
                if ($ok !== false) $imported++; else $failed++;
            }
            if ($top === null || version_compare($version, (string)$top['version'], '>')) $top = ['version'=>$version,'version_code'=>$meta['version_code'],'release_url'=>$meta['release_url']];
        }

        self::sync_legacy_stable_settings();
        $message = 'Releaseها همگام شدند؛ جدید: ' . $imported . '، بروزرسانی‌شده: ' . $updated . '، بدون تغییر: ' . $skipped . ($failed ? '، خطا: ' . $failed : '') . '. نسخه‌های جدید به‌صورت Beta ثبت می‌شوند.';
        self::save_status($failed && !$imported && !$updated ? 'error' : 'synced', $message, [
            'version' => (string)($top['version'] ?? ''),
            'version_code' => (int)($top['version_code'] ?? 0),
            'release_url' => (string)($top['release_url'] ?? ''),
            'source' => $source,
        ]);
        return ['ok' => !($failed && !$imported && !$updated), 'message' => $message, 'status' => self::status()];
    }

    private static function parse_release(array $release) {
        $version = (string)($release['_bluevpn_version'] ?? '');
        $assets = [];
        foreach ((array)($release['assets'] ?? []) as $asset) {
            if (!is_array($asset) || empty($asset['name']) || empty($asset['browser_download_url'])) continue;
            $assets[(string)$asset['name']] = $asset;
        }
        $manifest = [];
        if (isset($assets['release-manifest.json'])) {
            $json = self::download_text((string)$assets['release-manifest.json']['browser_download_url'], 1024 * 1024);
            if (!is_wp_error($json)) { $decoded = json_decode($json, true); if (is_array($decoded)) $manifest = $decoded; }
        }
        $hashes = [];
        if (isset($assets['SHA256SUMS.txt'])) {
            $text = self::download_text((string)$assets['SHA256SUMS.txt']['browser_download_url'], 512 * 1024);
            if (!is_wp_error($text)) {
                foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
                    if (preg_match('/^([a-fA-F0-9]{64})\s+\*?(.+)$/', trim($line), $m)) $hashes[trim($m[2])] = strtolower($m[1]);
                }
            }
        }
        $apkAssets=[]; $apkMeta=[];
        foreach ($assets as $name=>$asset) {
            if (!str_ends_with(strtolower($name), '.apk')) continue;
            $key=self::abi_key($name); if ($key==='') continue;
            $url=esc_url_raw((string)$asset['browser_download_url']); if ($url==='') continue;
            $apkAssets[$key]=$url;
            $digest=(string)($asset['digest']??''); $sha='';
            if (preg_match('/^sha256:([a-fA-F0-9]{64})$/',$digest,$m)) $sha=strtolower($m[1]);
            if ($sha==='' && isset($hashes[$name])) $sha=$hashes[$name];
            $apkMeta[$key]=['filename'=>sanitize_file_name($name),'sha256'=>$sha,'size'=>max(0,(int)($asset['size']??0))];
        }
        if (!$apkAssets) return new WP_Error('bluevpn_release_no_apk','APK داخل Release پیدا نشد.');
        $versionCode=max(0,(int)($manifest['version_code']??0)); if ($versionCode<=0) $versionCode=self::version_code($version);
        $defaultApk=$apkAssets['arm64-v8a']??$apkAssets['universal']??reset($apkAssets)?:'';
        $cfg=self::settings();
        $title=trim((string)($cfg['title_override']??'')); if($title==='')$title=trim((string)($manifest['update_title']??'')); if($title==='')$title=trim((string)($release['name']??'')); if($title==='')$title='BlueVPN '.$version;
        $message=trim((string)($cfg['message_override']??'')); if($message==='')$message=trim((string)($manifest['update_message']??'')); if($message==='')$message=trim(wp_strip_all_tags((string)($release['body']??''))); if($message==='')$message='نسخه جدید BlueVPN آماده است.';
        $published=trim((string)($manifest['published_at']??'')); if($published==='')$published=(string)($release['published_at']??'');
        return [
            'version_code'=>$versionCode, 'apk_url'=>$defaultApk, 'apk_assets'=>$apkAssets, 'apk_asset_meta'=>$apkMeta,
            'title'=>sanitize_text_field($title), 'message'=>sanitize_textarea_field($message),
            'release_url'=>esc_url_raw((string)($release['html_url']??self::repository_url())),
            'release_published_at'=>sanitize_text_field($published), 'build_number'=>max(0,(int)($manifest['build_number']??0)),
            'commit'=>sanitize_text_field((string)($manifest['commit']??'')),
        ];
    }

    public static function releases(int $limit = 100): array {
        global $wpdb; $table=BlueVPN_DB::table('app_releases'); $limit=max(1,min(300,$limit));
        $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY version_code DESC,id DESC LIMIT {$limit}",ARRAY_A)?:[];
        return array_map([self::class,'hydrate_release'],$rows);
    }

    public static function stable_release(): ?array { return self::latest_by_state('stable'); }
    public static function beta_release(): ?array { return self::latest_by_state('beta'); }
    public static function release_by_id(int $releaseId): ?array {
        if($releaseId<=0) return null;
        global $wpdb; $table=BlueVPN_DB::table('app_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        return $row?self::hydrate_release($row):null;
    }

    private static function latest_by_state(string $state): ?array {
        global $wpdb; $table=BlueVPN_DB::table('app_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE state=%s ORDER BY version_code DESC,id DESC LIMIT 1",$state),ARRAY_A);
        return $row?self::hydrate_release($row):null;
    }

    private static function hydrate_release(array $row): array {
        $row['id']=(int)($row['id']??0); $row['version_code']=(int)($row['version_code']??0); $row['force_update']=!empty($row['force_update']);
        $row['apk_assets']=BlueVPN_Utils::json_decode_array((string)($row['apk_assets_json']??''),[]);
        $row['apk_asset_meta']=BlueVPN_Utils::json_decode_array((string)($row['apk_asset_meta_json']??''),[]);
        unset($row['apk_assets_json'],$row['apk_asset_meta_json']);
        return $row;
    }

    public static function release_for_customer(?array $customer): array {
        $stable=self::stable_release();
        $isBeta=$customer && !empty($customer['beta_tester']) && !empty($customer['active']);
        $selected=$stable;
        $channel='stable';
        if($isBeta){
            $beta=self::beta_release();
            if($beta && (!$stable || (int)$beta['version_code'] >= (int)$stable['version_code'])) { $selected=$beta; $channel='beta'; }
        }
        if(!$selected){
            $settings=BlueVPN_DB::settings();
            $selected=[
                'id'=>0,'version'=>(string)($settings['latest_version']??'0.0.0'),'version_code'=>(int)($settings['latest_version_code']??0),
                'state'=>'stable','force_update'=>!empty($settings['force_update']),'title'=>(string)($settings['update_title']??''),'message'=>(string)($settings['update_message']??''),
                'apk_url'=>(string)($settings['apk_url']??''),'apk_assets'=>is_array($settings['apk_assets']??null)?$settings['apk_assets']:[],
                'apk_asset_meta'=>is_array($settings['apk_asset_meta']??null)?$settings['apk_asset_meta']:[], 'release_url'=>(string)($settings['release_url']??''),
                'release_published_at'=>(string)($settings['release_published_at']??''),'build_number'=>(int)($settings['release_build_number']??0),
                'commit_sha'=>(string)($settings['release_commit']??''),'source'=>'legacy_stable_fallback',
            ];
        }
        return ['release'=>$selected,'channel'=>$channel,'beta_tester'=>(bool)$isBeta];
    }

    public static function promote_to_stable(int $releaseId): array {
        global $wpdb; $table=BlueVPN_DB::table('app_releases');
        $target=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        if(!$target) return ['ok'=>false,'message'=>'نسخه پیدا نشد.'];
        $wpdb->query('START TRANSACTION');
        try{
            $now=BlueVPN_Utils::now_mysql();
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET state='archived',updated_at=%s WHERE state='stable' AND id<>%d",$now,$releaseId));
            $ok=$wpdb->update($table,['state'=>'stable','stopped_at'=>null,'promoted_at'=>$now,'updated_at'=>$now],['id'=>$releaseId]);
            if($ok===false) throw new RuntimeException('ذخیره وضعیت نسخه ناموفق بود.');
            $wpdb->query('COMMIT');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return ['ok'=>false,'message'=>$e->getMessage()];}
        self::sync_legacy_stable_settings();
        return ['ok'=>true,'message'=>'نسخه '.$target['version'].' بدون Build مجدد به Stable/انتشار رسمی ارتقا یافت.'];
    }

    public static function stop_beta(int $releaseId): array {
        global $wpdb; $table=BlueVPN_DB::table('app_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        if(!$row) return ['ok'=>false,'message'=>'نسخه پیدا نشد.'];
        if((string)$row['state']==='stable') return ['ok'=>false,'message'=>'نسخه Stable را نمی‌توان به‌عنوان Beta متوقف کرد. ابتدا نسخه دیگری را Stable کنید.'];
        $ok=$wpdb->update($table,['state'=>'stopped','stopped_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$releaseId]);
        return ['ok'=>$ok!==false,'message'=>$ok!==false?'انتشار آزمایشی '.$row['version'].' متوقف شد.':'توقف نسخه ناموفق بود.'];
    }

    public static function resume_beta(int $releaseId): array {
        global $wpdb; $table=BlueVPN_DB::table('app_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        if(!$row) return ['ok'=>false,'message'=>'نسخه پیدا نشد.'];
        if((string)$row['state']==='stable') return ['ok'=>false,'message'=>'این نسخه همین حالا Stable است.'];
        $ok=$wpdb->update($table,['state'=>'beta','stopped_at'=>null,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$releaseId]);
        return ['ok'=>$ok!==false,'message'=>$ok!==false?'نسخه '.$row['version'].' دوباره برای Beta Testerها فعال شد.':'فعال‌سازی نسخه ناموفق بود.'];
    }

    public static function toggle_force_update(int $releaseId): array {
        global $wpdb; $table=BlueVPN_DB::table('app_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,version,force_update,state FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        if(!$row) return ['ok'=>false,'message'=>'نسخه پیدا نشد.'];
        $new=!empty($row['force_update'])?0:1;
        $ok=$wpdb->update($table,['force_update'=>$new,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$releaseId]);
        if($ok!==false && (string)$row['state']==='stable') self::sync_legacy_stable_settings();
        return ['ok'=>$ok!==false,'message'=>$ok!==false?('اجبار بروزرسانی نسخه '.$row['version'].' '.($new?'فعال':'غیرفعال').' شد.'):'ذخیره سیاست اجبار ناموفق بود.'];
    }

    public static function sync_legacy_stable_settings(): void {
        $stable=self::stable_release(); if(!$stable)return;
        $settings=BlueVPN_DB::settings();
        $settings['latest_version']=(string)$stable['version'];
        $settings['latest_version_code']=(int)$stable['version_code'];
        $settings['apk_url']=(string)$stable['apk_url'];
        $settings['apk_assets']=$stable['apk_assets'];
        $settings['apk_asset_meta']=$stable['apk_asset_meta'];
        $settings['update_title']=(string)$stable['title'];
        $settings['update_message']=(string)$stable['message'];
        $settings['release_url']=(string)$stable['release_url'];
        $settings['release_published_at']=(string)$stable['release_published_at'];
        $settings['release_build_number']=(int)$stable['build_number'];
        $settings['release_commit']=(string)$stable['commit_sha'];
        $settings['update_source']='wordpress_release_channel_stable';
        $settings['github_repository']=self::repository();
        $settings['github_error']='';
        $settings['release_cache_seconds']=15;
        $settings['force_update']=!empty($stable['force_update']);
        BlueVPN_DB::save_settings($settings);
    }

    private static function release_fingerprint(array $release): string {
        $parts=[(string)($release['id']??''),(string)($release['tag_name']??''),(string)($release['updated_at']??'')];
        foreach((array)($release['assets']??[]) as $asset){if(!is_array($asset))continue;$parts[]=implode(':',[(string)($asset['id']??''),(string)($asset['name']??''),(string)($asset['updated_at']??''),(string)($asset['size']??''),(string)($asset['digest']??'')]);}
        return hash('sha256',implode('|',$parts));
    }

    private static function abi_key(string $filename): string {
        $name=strtolower($filename);
        if(str_contains($name,'arm64-v8a')||str_contains($name,'arm64'))return 'arm64-v8a';
        if(str_contains($name,'armeabi-v7a')||str_contains($name,'armeabi')||str_contains($name,'v7a'))return 'armeabi-v7a';
        if(str_contains($name,'universal'))return 'universal';
        return 'other';
    }

    private static function version_code(string $version): int {
        if(!preg_match('/^(\d+)\.(\d+)\.(\d+)$/',$version,$m))return 0;
        $minor=(int)$m[2];$patch=(int)$m[3];
        if($minor<0||$minor>10||$patch<0||$patch>10)return 0;
        return ((int)$m[1]*10000)+($minor*100)+$patch;
    }

    private static function download_text(string $url,int $max_bytes){
        $response=wp_remote_get($url,['timeout'=>12,'redirection'=>5,'headers'=>['User-Agent'=>'BlueVPN-Manager/'.BLUEVPN_MANAGER_VERSION]]);
        if(is_wp_error($response))return $response;
        $code=(int)wp_remote_retrieve_response_code($response);
        if($code<200||$code>=300)return new WP_Error('bluevpn_asset_http','GitHub asset HTTP '.$code);
        $body=(string)wp_remote_retrieve_body($response);
        if(strlen($body)>$max_bytes)return new WP_Error('bluevpn_asset_large','GitHub asset بیش از حد بزرگ است.');
        return $body;
    }
}
