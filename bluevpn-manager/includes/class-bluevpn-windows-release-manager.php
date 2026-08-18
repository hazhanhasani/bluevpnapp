<?php
if (!defined('ABSPATH')) exit;

/**
 * Windows release channels for BlueVPN.
 *
 * GitHub builds and stores the binaries. WordPress/MySQL is authoritative for
 * distribution:
 * - newly discovered bluevpn-windows-vX.Y.Z releases enter Beta
 * - authenticated beta_tester customers can receive Beta
 * - normal/anonymous users receive the latest Stable
 * - promotion Beta -> Stable never rebuilds the installer
 */
final class BlueVPN_Windows_Release_Manager {
    private const OPTION = 'bluevpn_windows_release_manager_settings_v1';
    private const STATUS_OPTION = 'bluevpn_windows_release_manager_status_v1';
    private const LAST_SYNC_OPTION = 'bluevpn_windows_release_last_sync_v1';
    private const CRON_HOOK = 'bluevpn_windows_release_fallback_sync';
    private const KICK_LOCK = 'bluevpn_windows_release_kick_lock_v1';
    private const SYNC_LOCK = 'bluevpn_windows_release_sync_lock_v1';
    private const DEFAULT_OWNER = 'hazhanhasani';
    private const DEFAULT_REPO = 'bluevpnapp';
    private const VALID_STATES = ['beta','stable','stopped','archived'];

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'background_sync']);
        add_action('admin_init', [self::class, 'ensure_schedule']);
        add_action('init', [self::class, 'maybe_kick'], 22);
        self::ensure_schedule();
    }

    public static function defaults(): array {
        return [
            'owner' => self::DEFAULT_OWNER,
            'repo' => self::DEFAULT_REPO,
            'auto_sync' => true,
            'auto_update_stable' => true,
            'auto_update_beta' => true,
            'minimum_version_stable' => '0.0.0',
            'minimum_version_beta' => '0.0.0',
            'site_channel' => 'stable',
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
        $clean['auto_update_stable'] = !empty($settings['auto_update_stable']);
        $clean['auto_update_beta'] = !empty($settings['auto_update_beta']);
        foreach (['minimum_version_stable','minimum_version_beta'] as $key) {
            $v = trim((string)($settings[$key] ?? '0.0.0'));
            $clean[$key] = preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : '0.0.0';
        }
        $siteChannel = sanitize_key((string)($settings['site_channel'] ?? 'stable'));
        $clean['site_channel'] = in_array($siteChannel, ['stable','beta'], true) ? $siteChannel : 'stable';
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
        $defaults = ['status'=>'never','message'=>'','version'=>'','version_code'=>0,'release_url'=>'','source'=>'','at'=>0];
        return is_array($value) ? wp_parse_args($value, $defaults) : $defaults;
    }

    private static function save_status(string $status, string $message, array $extra = []): void {
        update_option(self::STATUS_OPTION, array_merge([
            'status'=>sanitize_key($status),'message'=>sanitize_text_field($message),'version'=>'','version_code'=>0,
            'release_url'=>'','source'=>'','at'=>time(),
        ], $extra), false);
    }

    public static function last_sync(): int { return (int)get_option(self::LAST_SYNC_OPTION, 0); }

    public static function ensure_schedule(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event(self::CRON_HOOK) : null;
        if ($event && (string)($event->schedule ?? '') !== 'bluevpn_ten_minutes') self::unschedule();
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + 120, 'bluevpn_ten_minutes', self::CRON_HOOK);
    }

    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules['bluevpn_ten_minutes'])) {
            $schedules['bluevpn_ten_minutes'] = ['interval'=>10 * MINUTE_IN_SECONDS,'display'=>'BlueVPN every 10 minutes'];
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
        $cronUrl = site_url('/wp-cron.php?doing_wp_cron=' . rawurlencode(sprintf('%.22F', microtime(true))));
        wp_remote_post($cronUrl, ['timeout'=>1,'blocking'=>false,'sslverify'=>apply_filters('https_local_ssl_verify', false)]);
    }

    public static function background_sync($reason = null): void {
        try { if (!empty(self::settings()['auto_sync'])) self::sync_now(false, 'wordpress_background'); }
        finally { delete_transient(self::KICK_LOCK); }
    }

    public static function sync_now(bool $force = true, string $source = 'manual'): array {
        if (get_transient(self::SYNC_LOCK)) return ['ok'=>true,'message'=>'همگام‌سازی Windows دیگری در حال اجرا است.','status'=>self::status()];
        set_transient(self::SYNC_LOCK, '1', 90);
        try {
            $response = self::fetch_releases();
            if (is_wp_error($response)) {
                self::save_status('error', $response->get_error_message(), ['source'=>$source]);
                return ['ok'=>false,'message'=>$response->get_error_message(),'status'=>self::status()];
            }
            return self::ingest_releases($response, $source, $force);
        } finally { delete_transient(self::SYNC_LOCK); }
    }

    private static function fetch_releases() {
        $s = self::settings();
        $url = 'https://api.github.com/repos/' . rawurlencode($s['owner']) . '/' . rawurlencode($s['repo']) . '/releases?per_page=40';
        $response = wp_remote_get($url, ['timeout'=>12,'redirection'=>3,'headers'=>self::request_headers()]);
        if (is_wp_error($response)) return $response;
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code !== 200) return new WP_Error('bluevpn_windows_release_http', 'GitHub API HTTP ' . $code);
        $releases = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($releases) ? $releases : new WP_Error('bluevpn_windows_release_json', 'پاسخ Releaseهای Windows معتبر نیست.');
    }

    private static function request_headers(): array {
        return [
            'Accept'=>'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28',
            'User-Agent'=>'BlueVPN-Windows-Release-Manager/' . BLUEVPN_MANAGER_VERSION . '; ' . home_url('/'),
        ];
    }

    public static function ingest_releases(array $releases, string $source = 'shared_github_poll', bool $force = false): array {
        if (!$force && empty(self::settings()['auto_sync'])) return ['ok'=>true,'message'=>'همگام‌سازی خودکار Windows غیرفعال است.','status'=>self::status()];
        update_option(self::LAST_SYNC_OPTION, time(), false);
        $candidates = [];
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) continue;
            $tag = trim((string)($release['tag_name'] ?? ''));
            if (!preg_match('/^bluevpn-windows-v(\d+\.\d+\.\d+)$/', $tag, $m)) continue;
            $release['_bluevpn_version'] = $m[1];
            $candidates[] = $release;
        }
        if (!$candidates) {
            self::save_status('no_release', 'Release ویندوز با Tag استاندارد bluevpn-windows-vX.Y.Z پیدا نشد.', ['source'=>$source]);
            return ['ok'=>false,'message'=>'Release ویندوز پیدا نشد.','status'=>self::status()];
        }
        usort($candidates, static fn($a,$b)=>version_compare((string)$b['_bluevpn_version'], (string)$a['_bluevpn_version']));

        global $wpdb;
        $table = BlueVPN_DB::table('windows_releases');
        $imported=0; $updated=0; $skipped=0; $failed=0; $top=null;
        foreach ($candidates as $release) {
            $version=(string)$release['_bluevpn_version'];
            $fingerprint=self::release_fingerprint($release);
            $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE version=%s LIMIT 1",$version),ARRAY_A);
            if ($existing && $fingerprint!=='' && hash_equals((string)($existing['fingerprint']??''),$fingerprint)) { $skipped++; continue; }
            if ($existing && (string)($existing['state']??'')==='stable' && (string)($existing['fingerprint']??'')!=='' && $fingerprint!=='' && !hash_equals((string)$existing['fingerprint'],$fingerprint)) {
                // Stable installers are immutable. A same-version rebuild must not silently replace public binaries.
                $failed++; continue;
            }
            $meta=self::parse_release($release);
            if (is_wp_error($meta)) { $failed++; continue; }
            $state=$existing && in_array((string)$existing['state'],self::VALID_STATES,true)?(string)$existing['state']:'beta';
            $now=BlueVPN_Utils::now_mysql();
            $row=[
                'github_release_id'=>max(0,(int)($release['id']??0))?:null,
                'version'=>$version,'version_code'=>(int)$meta['version_code'],'state'=>$state,
                'force_update'=>$existing?(int)$existing['force_update']:0,
                'title'=>(string)$meta['title'],'message'=>(string)$meta['message'],
                'installer_x64_url'=>(string)$meta['installer_x64_url'],'installer_arm64_url'=>(string)$meta['installer_arm64_url'],
                'portable_x64_url'=>(string)$meta['portable_x64_url'],'portable_arm64_url'=>(string)$meta['portable_arm64_url'],
                'asset_meta_json'=>BlueVPN_Utils::json_encode($meta['asset_meta']),
                'release_url'=>(string)$meta['release_url'],'release_published_at'=>(string)$meta['release_published_at'],
                'commit_sha'=>(string)$meta['commit'],'fingerprint'=>$fingerprint,'source'=>sanitize_key($source),
                'promoted_at'=>$existing['promoted_at']??null,'stopped_at'=>$existing['stopped_at']??null,
                'created_at'=>$existing['created_at']??$now,'updated_at'=>$now,
            ];
            if ($existing) {
                $ok=$wpdb->update($table,$row,['id'=>(int)$existing['id']]);
                if ($ok!==false)$updated++;else$failed++;
            } else {
                $ok=$wpdb->insert($table,$row);
                if ($ok!==false)$imported++;else$failed++;
            }
            if ($top===null || version_compare($version,(string)$top['version'],'>')) $top=['version'=>$version,'version_code'=>$meta['version_code'],'release_url'=>$meta['release_url']];
        }
        $message='Releaseهای Windows همگام شدند؛ جدید: '.$imported.'، بروزرسانی‌شده: '.$updated.'، بدون تغییر: '.$skipped.($failed?'، خطا: '.$failed:'').'. نسخه‌های جدید به‌صورت Beta ثبت می‌شوند.';
        self::save_status($failed && !$imported && !$updated?'error':'synced',$message,[
            'version'=>(string)($top['version']??''),'version_code'=>(int)($top['version_code']??0),'release_url'=>(string)($top['release_url']??''),'source'=>$source,
        ]);
        return ['ok'=>!($failed && !$imported && !$updated),'message'=>$message,'status'=>self::status()];
    }

    private static function parse_release(array $release) {
        $version=(string)($release['_bluevpn_version']??'');
        $assets=[];
        foreach ((array)($release['assets']??[]) as $asset) {
            if (!is_array($asset)||empty($asset['name'])||empty($asset['browser_download_url'])) continue;
            $assets[(string)$asset['name']]=$asset;
        }
        $names=[
            'installer_x64'=>'BlueVPN-Setup-'.$version.'-win-x64.exe',
            'installer_arm64'=>'BlueVPN-Setup-'.$version.'-win-arm64.exe',
            'portable_x64'=>'BlueVPN-Windows-'.$version.'-win-x64.zip',
            'portable_arm64'=>'BlueVPN-Windows-'.$version.'-win-arm64.zip',
        ];
        if (!isset($assets[$names['installer_x64']],$assets[$names['installer_arm64']])) {
            return new WP_Error('bluevpn_windows_release_no_installer','Installer کامل x64/ARM64 داخل Release ویندوز پیدا نشد.');
        }
        $urls=[]; $meta=[];
        foreach ($names as $key=>$name) {
            if (!isset($assets[$name])) { $urls[$key]=''; continue; }
            $asset=$assets[$name];
            $url=esc_url_raw((string)($asset['browser_download_url']??''));
            if ($url==='') { $urls[$key]=''; continue; }
            $sha=self::asset_sha256($name,$asset,$assets);
            $urls[$key]=$url;
            $meta[$key]=[
                'filename'=>sanitize_file_name($name),'sha256'=>$sha,'size'=>max(0,(int)($asset['size']??0)),
                'architecture'=>str_contains($key,'arm64')?'win-arm64':'win-x64',
                'kind'=>str_starts_with($key,'installer_')?'installer':'portable',
            ];
        }
        $cfg=self::settings();
        $title=trim((string)($cfg['title_override']??''));
        if($title==='')$title=trim((string)($release['name']??''));
        if($title==='')$title='BlueVPN Windows '.$version;
        $message=trim((string)($cfg['message_override']??''));
        if($message==='')$message=trim(wp_strip_all_tags((string)($release['body']??'')));
        if($message==='')$message='نسخه جدید BlueVPN برای Windows آماده است.';
        return [
            'version_code'=>self::version_code($version),
            'installer_x64_url'=>$urls['installer_x64']??'','installer_arm64_url'=>$urls['installer_arm64']??'',
            'portable_x64_url'=>$urls['portable_x64']??'','portable_arm64_url'=>$urls['portable_arm64']??'',
            'asset_meta'=>$meta,'title'=>sanitize_text_field($title),'message'=>sanitize_textarea_field($message),
            'release_url'=>esc_url_raw((string)($release['html_url']??self::repository_url())),
            'release_published_at'=>sanitize_text_field((string)($release['published_at']??'')),
            'commit'=>sanitize_text_field((string)($release['target_commitish']??'')),
        ];
    }

    private static function asset_sha256(string $name,array $asset,array $assets): string {
        $digest=(string)($asset['digest']??'');
        if (preg_match('/^sha256:([a-fA-F0-9]{64})$/',$digest,$m)) return strtolower($m[1]);
        $sidecarName=$name.'.sha256';
        if (!isset($assets[$sidecarName])) return '';
        $text=self::download_text((string)$assets[$sidecarName]['browser_download_url'],8192);
        if (is_wp_error($text)) return '';
        return preg_match('/\b([a-fA-F0-9]{64})\b/',(string)$text,$m)?strtolower($m[1]):'';
    }

    public static function releases(int $limit=100): array {
        global $wpdb; $table=BlueVPN_DB::table('windows_releases'); $limit=max(1,min(300,$limit));
        $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY version_code DESC,id DESC LIMIT {$limit}",ARRAY_A)?:[];
        return array_map([self::class,'hydrate_release'],$rows);
    }
    public static function stable_release(): ?array { return self::latest_by_state('stable'); }
    public static function beta_release(): ?array { return self::latest_by_state('beta'); }
    private static function latest_by_state(string $state): ?array {
        global $wpdb; $table=BlueVPN_DB::table('windows_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE state=%s ORDER BY version_code DESC,id DESC LIMIT 1",$state),ARRAY_A);
        return $row?self::hydrate_release($row):null;
    }
    private static function hydrate_release(array $row): array {
        $row['id']=(int)($row['id']??0);$row['version_code']=(int)($row['version_code']??0);$row['force_update']=!empty($row['force_update']);
        $row['asset_meta']=BlueVPN_Utils::json_decode_array((string)($row['asset_meta_json']??''),[]);unset($row['asset_meta_json']);
        return $row;
    }

    public static function release_for_customer(?array $customer): array {
        $stable=self::stable_release();
        $isBeta=$customer && !empty($customer['beta_tester']) && !empty($customer['active']);
        $selected=$stable; $channel='stable';
        if($isBeta){
            $beta=self::beta_release();
            if($beta && (!$stable || (int)$beta['version_code'] >= (int)$stable['version_code'])){$selected=$beta;$channel='beta';}
        }
        return ['release'=>$selected,'channel'=>$channel,'beta_tester'=>(bool)$isBeta];
    }

    public static function public_site_release(): ?array {
        $cfg=self::settings();
        $channel=(string)($cfg['site_channel']??'stable');
        if($channel==='beta') return self::beta_release() ?: self::stable_release();
        return self::stable_release();
    }

    public static function installer_for_arch(array $release,string $arch): array {
        $arch=strtolower(trim($arch));
        $isArm=str_contains($arch,'arm64')||str_contains($arch,'aarch64');
        $key=$isArm?'installer_arm64':'installer_x64';
        $meta=is_array($release['asset_meta']??null)?($release['asset_meta'][$key]??[]):[];
        return [
            'architecture'=>$isArm?'win-arm64':'win-x64',
            'url'=>(string)($release[$key.'_url']??''),
            'filename'=>(string)($meta['filename']??''),'sha256'=>(string)($meta['sha256']??''),'size'=>(int)($meta['size']??0),
        ];
    }

    public static function promote_to_stable(int $releaseId): array {
        global $wpdb;$table=BlueVPN_DB::table('windows_releases');
        $target=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        if(!$target)return ['ok'=>false,'message'=>'نسخه Windows پیدا نشد.'];
        $wpdb->query('START TRANSACTION');
        try{
            $now=BlueVPN_Utils::now_mysql();
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET state='archived',updated_at=%s WHERE state='stable' AND id<>%d",$now,$releaseId));
            $ok=$wpdb->update($table,['state'=>'stable','stopped_at'=>null,'promoted_at'=>$now,'updated_at'=>$now],['id'=>$releaseId]);
            if($ok===false)throw new RuntimeException('ذخیره وضعیت Windows ناموفق بود.');
            $wpdb->query('COMMIT');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return ['ok'=>false,'message'=>$e->getMessage()];}
        return ['ok'=>true,'message'=>'نسخه Windows '.$target['version'].' بدون Build مجدد به Stable/انتشار رسمی ارتقا یافت.'];
    }

    public static function stop_beta(int $releaseId): array {
        global $wpdb;$table=BlueVPN_DB::table('windows_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        if(!$row)return ['ok'=>false,'message'=>'نسخه Windows پیدا نشد.'];
        if((string)$row['state']==='stable')return ['ok'=>false,'message'=>'نسخه Stable ویندوز را نمی‌توان به‌عنوان Beta متوقف کرد. ابتدا نسخه دیگری را Stable کنید.'];
        $now=BlueVPN_Utils::now_mysql();$ok=$wpdb->update($table,['state'=>'stopped','stopped_at'=>$now,'updated_at'=>$now],['id'=>$releaseId]);
        return ['ok'=>$ok!==false,'message'=>$ok!==false?'انتشار آزمایشی Windows '.$row['version'].' متوقف شد.':'توقف نسخه Windows ناموفق بود.'];
    }

    public static function resume_beta(int $releaseId): array {
        global $wpdb;$table=BlueVPN_DB::table('windows_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        if(!$row)return ['ok'=>false,'message'=>'نسخه Windows پیدا نشد.'];
        if((string)$row['state']==='stable')return ['ok'=>false,'message'=>'این نسخه Windows همین حالا Stable است.'];
        $now=BlueVPN_Utils::now_mysql();$ok=$wpdb->update($table,['state'=>'beta','stopped_at'=>null,'updated_at'=>$now],['id'=>$releaseId]);
        return ['ok'=>$ok!==false,'message'=>$ok!==false?'نسخه Windows '.$row['version'].' دوباره برای Beta Testerها فعال شد.':'فعال‌سازی Beta ویندوز ناموفق بود.'];
    }

    public static function toggle_force_update(int $releaseId): array {
        global $wpdb;$table=BlueVPN_DB::table('windows_releases');
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,version,force_update,state FROM {$table} WHERE id=%d LIMIT 1",$releaseId),ARRAY_A);
        if(!$row)return ['ok'=>false,'message'=>'نسخه Windows پیدا نشد.'];
        $new=!empty($row['force_update'])?0:1;$ok=$wpdb->update($table,['force_update'=>$new,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$releaseId]);
        return ['ok'=>$ok!==false,'message'=>$ok!==false?('اجبار بروزرسانی Windows '.$row['version'].' '.($new?'فعال':'غیرفعال').' شد.'):'ذخیره سیاست اجبار Windows ناموفق بود.'];
    }

    private static function release_fingerprint(array $release): string {
        $parts=[(string)($release['id']??''),(string)($release['tag_name']??''),(string)($release['updated_at']??'')];
        foreach((array)($release['assets']??[]) as $asset){if(!is_array($asset))continue;$parts[]=implode(':',[(string)($asset['id']??''),(string)($asset['name']??''),(string)($asset['updated_at']??''),(string)($asset['size']??''),(string)($asset['digest']??'')]);}
        return hash('sha256',implode('|',$parts));
    }

    private static function version_code(string $version): int {
        if(!preg_match('/^(\d+)\.(\d+)\.(\d+)$/',$version,$m))return 0;
        return ((int)$m[1]*10000)+((int)$m[2]*100)+(int)$m[3];
    }

    private static function download_text(string $url,int $maxBytes){
        $response=wp_remote_get($url,['timeout'=>12,'redirection'=>5,'headers'=>['User-Agent'=>'BlueVPN-Manager/'.BLUEVPN_MANAGER_VERSION]]);
        if(is_wp_error($response))return $response;
        $code=(int)wp_remote_retrieve_response_code($response);if($code<200||$code>=300)return new WP_Error('bluevpn_windows_asset_http','GitHub asset HTTP '.$code);
        $body=(string)wp_remote_retrieve_body($response);if(strlen($body)>$maxBytes)return new WP_Error('bluevpn_windows_asset_large','GitHub asset بیش از حد بزرگ است.');
        return $body;
    }
}
