=== BlueVPN Manager ===
Version: 6.0.4
Stable tag: 6.0.4
Requires PHP: 8.0

== 5.2.2 ==
* Connection/release hardening across Android, Windows, Manager, Telegram bot and Site.
* Sentinel respects intentionally suppressed PHP warnings (including cleanup @unlink paths) and ignores expected client-validation noise such as EMAIL_INVALID while preserving real failures.
* /diagnose surfaces the latest GitHub run, failed job and failed step; failed build jobs persist the same actionable step summary.
* GitHub Actions failure delivery now extracts real Gradle/regression error lines, includes a bounded log tail, and uploads complete build-failure diagnostics.

== 5.1.9 ==
- Gateway Autopilot به‌صورت پیش‌فرض روشن است؛ Capacity از CPU/RAM واقعی Agent محاسبه می‌شود.
- Auto-Drain/Auto-Recover و Session Handoff با ACK و overlap امن 60 ثانیه‌ای.
- فرم Gateway برای استفاده روزمره به Public Host ساده شد.
- Schema دیتابیس 1.29.0 با telemetry سخت‌افزار و جدول gateway_session_migrations.

== 5.1.8 ==
- Safe Gateway Rollout با config_generation، ACK واقعی Agent، Canary و مراحل 10% → 25% → 50% → 100%.
- rollback خودکار در صورت config mismatch، خطای runtime یا timeout ACK.
- quota/revoke و policy زنده از rollout ساختاری مستقل باقی ماندند.

== 5.1.7 ==
- رفع False Positive تنظیمات خود Sentinel و resolve خودکار incident قدیمی.
- Watchdog خودکار Deploy Bot، ترمیم Webhook و Recovery Guard برای نصب Manager.

== 5.1.6 ==
- فاز سه Gateway: انتخاب خودکار Node سالم بر اساس Priority/Capacity/Region و ساخت Primary/Standby برای هر پلن.
- اضافه‌شدن Gateway replicas قابل تنظیم از 1 تا 3، Drain mode و Reconcile دستی/خودکار یک‌دقیقه‌ای برای Failover.
- Heartbeat جدید Agent شامل Xray health، تعداد Session، صف مصرف، CPU/RAM و uptime است و Node آفلاین/پر/Drain برای کاربر جدید انتخاب نمی‌شود.
- صف مصرف Agent بلافاصله بعد از Xray reset=true روی دیسک ذخیره می‌شود تا قطع شبکه/Crash باعث گم‌شدن byte delta نشود.
- اضافه‌شدن agent_epoch + sequence replay guard و قفل FOR UPDATE روی حساب کاربر برای جلوگیری از race بین چند Gateway هنگام محاسبه سهمیه مرکزی.
- Hysteria2/TUIC با sing-box sidecar محلی اجرا می‌شوند و Xray همچنان مرجع metering است.

== 5.1.4 ==
- اضافه‌شدن معماری gateway_metered برای پلن پولی؛ مصرف Upload/Download از Gateway خود BlueVPN در MySQL حساب می‌شود و آمار Provider مرجع حجم نیست.
- اضافه‌شدن Sourceهای اشتراک دستی شامل Subscription URL رمزنگاری‌شده و Inline Config و تجمیع آن‌ها با Marzban/PasarGuard/GuardCore در Pool واحد سمت سرور.
- اپ‌ها در حالت Gateway فقط VLESS/TLS مربوط به Gatewayهای BlueVPN را دریافت می‌کنند و لینک‌های اصلی Provider/ساب دستی به کلاینت داده نمی‌شود.
- اضافه‌شدن Agent لینوکسی HMAC-authenticated با Xray per-user stats، ledger idempotent مصرف و قطع fail-closed پس از رسیدن به سقف حجم.
- در فاز اول Gateway، Upstreamهای VLESS/VMess/Trojan/Shadowsocks اجرا می‌شوند؛ Hysteria2/TUIC در Pool حفظ ولی توسط Agent Xray فعلی skip می‌شوند.

== 5.1.3 ==
- تکمیل خرید سرویس در نسخه Windows با سفارش معتبر BlueVPN/BluPal، بازکردن درگاه HTTPS و بررسی خودکار فعال‌شدن اشتراک.
- اضافه‌شدن مرکز پشتیبانی Windows روی API موجود گفتگو/پیام/بخش‌ها و ثبت صحیح source=windows برای درخواست‌های این کلاینت.
- اضافه‌شدن تم روشن، تیره و مطابق Windows با ذخیره انتخاب کاربر.
- تپسل در این نسخه عمداً بدون تغییر باقی مانده و به نسخه بعدی موکول شده است.

== 5.1.2 ==
- بازطراحی بخش حساب/اشتراک و منوی فنی Windows برای جلوگیری از فشردگی و روی‌هم‌افتادن متن‌ها در DPI واقعی.
- کارت‌های پلن ویژه، وضعیت اشتراک، بروزرسانی و BlueAI با فاصله، wrap و hierarchy خواناتر نمایش داده می‌شوند.
- اصلاح 5.1.1 نسبت تصویر تبلیغات و چیدمان صفحه اصلی بدون تغییر حفظ شده است.

== 5.1.1 ==
- هماهنگ‌سازی نسخه پلتفرم با اصلاح چیدمان واکنش‌گرای Windows و نسبت تصویر تبلیغات؛ قرارداد API تبلیغات بدون تغییر باقی مانده است.

== 5.1.0 ==
- Removes the Windows build dependency on GitHub REST release-metadata calls that can hit the shared runner-IP rate limit.
- Downloads pinned v2rayN release assets directly and verifies per-architecture SHA-256; Aether uses its release-provided .sha256 sidecar.
- Retries transient download failures with bounded backoff while keeping all BlueAI/fast-connect/authentication UI fixes from 5.0.10.

== 5.0.10 ==
- Fixes the Windows BlueAI C# compile failure caused by using the reserved keyword `operator` as an anonymous-object property identifier.
- Keeps the serialized JSON key as `operator` by using the escaped C# identifier `@operator`.
- Adds a Windows validator and unit-test guard that rejects unescaped reserved-keyword payload assignments before GitHub build.

== 5.0.9 ==
- Fixes the Windows WPF authentication drawer compile failure caused by applying FontFamily directly to a Border.
- Moves the authentication font to the Window-level WPF control scope using the reliable Segoe UI family.
- Adds a static Windows validator/test gate that rejects FontFamily on non-Control WPF containers before GitHub build.

== 5.0.8 ==
- Restores Windows BlueAI as an active route optimizer with cached/cloud scoring, failure learning and immediate live connection heartbeats.
- Speeds up Windows and Android connection verification while keeping fail-closed tunnel validation.
- Hardens Windows strict TUN IPv6/DNS routing and preserves proxy restore backups until restoration succeeds.
- Rebuilds Windows login/register colors, typography and control sizing around the native BlueVPN light/blue palette.
- Validates sing-box JSON generated by the real Windows C# builders in GitHub Actions.

== 5.0.7 ==
- Migrates Windows sing-box TUN configs to the 1.13+ route-action schema shipped with the current v2rayN runtime.
- Removes deprecated sing-box legacy special outbounds from Windows runtime configs.
- Makes BlueVPN Sentinel Telegram delivery HTML-safe and chunk-safe so failed-build diagnostics are delivered reliably.

== 5.0.6 ==
- Restores a compact responsive admin layout across all BlueVPN Manager pages.
- Removes forced 960px/980px table widths and oversized 5.0.5 controls that caused panel breakage.
- Keeps wide tables inside scroll containers on desktop and labeled card mode on mobile.
- Hardens Windows release synchronization against transient GitHub API release-metadata failures.

== 5.0.5 ==
- Synchronizes BlueVPN Manager with the 5.0.5 coordinated application release.
- Carries the Windows UI/update/runtime fixes while preserving the existing WordPress/MySQL control-plane behavior.
- Android, Manager, Theme and Windows release metadata are synchronized on 5.0.5 / 50005.

== 5.0.3 ==
- Logout now removes the authenticated device sessions and releases its device slot immediately.
- Legacy orphaned app device rows left active by older logout logic are self-healed before enforcing device limits.
- Missing Free WARP scan/IP mode form fields are handled safely without PHP 8.4 undefined-array-key warnings.
- Windows logout now revokes the server session before clearing local authentication state.
- Windows control-plane requests prefer a direct path and can retry through the system proxy on transport/TLS failures without bypassing certificate validation.

== 4.17.10 ==
- WordPress/MySQL `customers.subscription_expire` is now the only authoritative paid entitlement expiry.
- Provider sync can no longer overwrite the canonical expiry with PasarGuard/Marzban/GuardCore dates.
- Payment activation snapshots one target expiry per paid order; retries and Cutover reconciliation reuse it and never add plan duration twice.
- Manual activation remains an intentional renewal path and explicitly calculates a new target expiry once.
- One-time repair removes only provable duplicate-duration inflation from historical repeated provisioning attempts and schedules provider resync.
- Provider expiry drift is detected, auto-healed back to the canonical WordPress expiry, and reported through Sentinel as `SUBSCRIPTION_EXPIRY_DRIFT`.

== 4.17.3 ==
- Successful Windows builds push signed release metadata directly into BlueVPN Manager/MySQL; site publication no longer depends on a live GitHub Releases API lookup.
- Coordinated Stable intent is persisted before any GitHub fallback call, so a timeout cannot lose the administrator's publish request.
- GitHub polling is retrying/cache-friendly fallback only; the public theme does not call GitHub while BlueVPN Manager is active.
- Windows release metadata push is HMAC-authenticated and uses the existing deployment bot secret without exposing it through REST.

== 4.17.1 ==
- Deploy Bot installs the exact BlueVPN Manager bundled in the validated uploaded ZIP before depending on a dedicated GitHub Manager Release.
- A delayed/missing Manager Release no longer fails a full deploy when the local source installation succeeded; Release publication remains a background/update channel.
- Adds exact-tag GitHub Release retry for eventual consistency when the Release path is used as fallback.
- Hardens project-root detection for nested wrapper directories and one unambiguous versioned Manager folder.
- Sentinel reports failed Deploy Bot jobs immediately and no longer re-counts an unchanged failed row every minute.
- Android, Manager, Theme and Windows source metadata are synchronized on 4.17.1.

== 4.16.9 ==
- Fixes the Windows RuntimeLocator architecture-name collision that caused CS1061 on both x64 and ARM64 builds.
- Preflights GitHub Actions cancellation and classifies expected 403/404/409 results instead of emitting generic HTTP warnings.
- Upgrades Windows artifact upload/download actions to v6 for the Node.js 24 runner transition.
- Elementor empty header/footer output now falls back safely without being reported as a runtime error; BlueVPN-managed truly empty templates can self-heal without overwriting user-edited templates.
- Android, Manager, Theme and Windows source metadata are synchronized on 4.16.9.

== 4.16.7 ==
- Removes every direct plugin call into WordPress Core `spawn_cron()` and replaces it with BlueVPN's safe non-blocking loopback cron nudge, preventing `WP_CRON_LOCK_TIMEOUT` crashes on cPanel execution paths.
- Makes native WordPress/MySQL cutover finalization revision-idempotent and automatically resolves the historical `NATIVE_CUTOVER_FINALIZE_FAILED` incident after a successful pass.
- Rebuilds the Sentinel admin screen for mobile: responsive KPI cards, toggle controls, stacked actions and event-card rendering instead of a crushed desktop table.
- Prevents a stale mobile navigation drawer from reopening after browser back/forward cache restoration.
- Android, Manager, Theme and Windows source metadata are synchronized on 4.16.7.

== 4.16.6 ==
- WordPress/MySQL is the permanent BlueVPN control plane; legacy migration schedules, source URL and token are retired automatically.
- Old paid_needs_sync/partial_needs_sync orders are reconciled asynchronously with a bounded three-attempt repair flow.
- Expected PasarGuard /api/groups 403/404 capability fallbacks no longer create false Sentinel alerts; final fallback failures remain visible.
- Fixes PHP E_WARNING notices for undefined $base, $mode and $warpEnabled in the advertising payload.
- Android, Manager, Theme and Windows source metadata are synchronized on 4.16.6.

== 4.16.5 ==
- Sentinel Telegram timestamps are rendered in Asia/Tehran with the Persian/Jalali date instead of raw UTC.
- Health warnings are classified separately from runtime failures; recovered health incidents are automatically resolved.
- Payment health alerts list the affected order code/status/age/reason and direct the admin to the Payments screen.
- Cutover health reports the exact migration/app flags and a concrete action instead of a generic warning.
- Android, Manager, Theme and Windows source metadata are synchronized on 4.16.5.

== 4.16.4 ==
- Adds BlueVPN Sentinel: centralized runtime monitoring for PHP, MySQL, REST, HTTP, cron, provider panels, provisioning, SMS, payments, plugin/theme and update errors.
- Adds a persistent deduplicated incident store and a BlueVPN Manager monitoring dashboard with Telegram test, health scan and retention controls.
- Adds an independent GitHub workflow_run sentinel that reports failed jobs/steps/annotations/log excerpts even when the original workflow dies before its own notification step.
- Adds full-project syntax/regression CI and five-minute external WordPress/control-plane health probes.
- Android, Manager, Theme and Windows source metadata are synchronized on 4.16.4.


== 4.16.3 ==
- Adds first-class Windows Stable/Beta release channels managed from BlueVPN Manager.
- New Windows GitHub builds are discovered as Beta; administrators can promote the same installer to Stable without rebuilding.
- Adds independent Windows automatic-update policies, minimum versions, force-update controls, x64/ARM64 asset health and public-site channel selection.
- Windows clients now obtain update/channel policy from WordPress instead of deciding Beta/Stable directly from GitHub.
- Android, Manager, Theme and Windows source metadata are synchronized on 4.16.3.


== 4.16.3 ==
- Defines Windows download/channel metadata alongside Android without changing the public Android stable channel.
- Windows website releases are explicitly marked beta/stable and consumed dynamically by the site theme.
- Elementor and PHP download pages now use one Windows-aware rendering contract.
- Android, Manager, Theme and Windows source metadata are synchronized on 4.16.3.

== 4.15.10 ==
- Telegram deploy ZIP intake now distinguishes transport truncation from an invalid/corrupt uploaded source archive.
- Exact expected/received byte parity no longer triggers five pointless re-downloads for structural ZIP failures.
- Adds EOCD/Central Directory preflight, SHA-256 diagnostics and named ZipArchive/libzip error reporting (for example code 19 = ER_NOZIP).
- Android, Manager, Theme and Windows release metadata are synchronized on 4.15.10.

== 4.15.9 ==
- Fixes Windows ARM64 Xray runtime validation on GitHub Actions: ARM64 binaries are PE-validated instead of executed on an x64 runner.
- Adds stage-specific Xray runtime logs to Telegram failure diagnostics.
- Android, Manager, Theme and Windows release metadata are synchronized on 4.15.9.


== 4.15.7 ==
- Adds BlueVPN Windows Phase 1 with .NET 10 WPF, the existing BlueVPN account/plan APIs, Xray/Wintun TUN and GitHub x64/arm64 builds.
- Windows Premium uses the same account subscription URL as Android; Windows Free Phase 1 uses the existing curated Free Pool.
- Android, Manager, Theme and Windows release metadata are synchronized on 4.15.7.

== 4.15.6 ==
- Manual CRM activation uses the exact existing `admin_subscription_activated` event used by BlueVPN Manual Activation.
- Adds «ارسال فعال‌سازی» for existing manual customers.
- Fixes zero-history cases caused by using `subscription_activated` while the configured current Pattern belonged to manual activation.
- Android, Manager and Site theme remain synchronized on 4.15.6.

== 4.15.5 ==
- First plan assignment for a manual customer now uses the exact existing app `subscription_activated` SMS event.
- Activation SMS is attempted immediately in the same admin request instead of waiting only for cron/shutdown queue flush.
- Renewal and plan-change foreground messages also attempt immediate delivery; provider failures remain durable and retry automatically.
- Android, Manager and Site theme remain synchronized on 4.15.5.

== 4.15.4 ==
- Manual customers now select directly from the current BlueVPN plans; no separate manual plan name/duration.
- New manual customers automatically use the existing «فعال‌سازی دستی توسط مدیریت» SMS message/pattern.
- Renewals use the existing «تمدید اشتراک» message and the selected plan's exact duration_days.
- Expiry reminders and expiry notices reuse the existing subscription_reminder/subscription_expired messages and the same reminder-days settings.
- Removes duplicate manual-only SMS templates and keeps CRM fully isolated from VPN provisioning/entitlements.
- Android, Manager and Site theme stay synchronized on 4.15.4.

== 4.15.3 ==
- پوسته، افزونه و اپ هر سه دقیقاً روی نسخه 4.15.3 قفل و همگام شدند.
- Android app, BlueVPN Manager and BlueVPN Site theme now use exactly the same release version.
- Full-project deployment is rejected if the theme, Manager, branding or release metadata versions differ.
- Theme GitHub release workflow validates its version against the global BlueVPN release before publishing.

== 4.15.2 ==
- Moves «مشتریان دستی» into the visible Services group beside «فعال‌سازی دستی».
- Adds a direct «بازکردن مشتریان دستی» button on the Manual Activation page.
- CRM/SMS behavior and VPN-entitlement isolation remain unchanged.

== 4.15.1 ==
- Adds «مشتریان دستی» as a CRM-only section independent from BlueVPN app users/providers/entitlements.
- Supports manual add/edit, Jalali start/expiry dates, active/SMS switches, notes and service/app labels.
- Adds one-click N-day renewal and automatic activation/renewal/reminder/expiry SMS events.
- Adds CSV bulk import and manual-customer SMS delivery history.
- Reminder days reuse the existing SMS/OTP reminder-days policy; no duplicate reminder is queued for the same expiry cycle/day.

== 4.15.0 ==
- Distributes Tapsell across Free Home, Locations, Account/Plans and Support instead of one ad hub.
- Standard Banner now shares the existing BlueVPN campaign carousel and creates no extra Home slot.
- Adds independent enable, Zone ID, cooldown and daily cap controls for all seven Tapsell placements.
- Rewarded Video grants the exact server-configured minutes through an idempotent /free/reward/claim ledger.
- Premium hard boundary: no Tapsell request/preload/show; only BlueVPN first-party banners remain.

== 4.14.10 ==
- Fixes Android compile error: unresolved Tapsell.initialize caused by pinning 1.4.0-alpha02 while using manual initialization.
- Pins Tapsell Mediation core, legacy and legacy-ima-extension to 1.4.0-alpha03 where manual initialize(Context) and AUTO_INIT opt-out are supported.
- Keeps all 4.14.9 Free-only ad surfaces and Premium no-Tapsell isolation unchanged.

== 4.14.9 ==
- Adds Free-only in-app Tapsell surfaces for Rewarded Video, Standard Banner, Native Banner, Native Video and PreRoll.
- Interstitial Video and Interstitial Banner are a Free post-connect waterfall before BlueVPN Story fallback.
- Premium hard gate: no Tapsell request/show/preload/surface; Premium keeps only BlueVPN first-party campaign banners.
- Rewarded Video can grant configurable extra Free-session minutes (default 15).
- Pins Tapsell Mediation to published 1.4.0-alpha03 and adds legacy-ima-extension for PreRoll support.

== 4.14.8 ==
- Adds seven independent Tapsell placement Zone IDs: Rewarded Video, Interstitial Video, Pre-roll Video, Native Video, Standard Banner, Interstitial Banner and Native Banner.
- Mobile Config publishes a typed zones map while preserving the legacy interstitial_zone_id compatibility key.
- Post-connect Free advertising prefers Interstitial Video and falls back to Interstitial Banner when configured.
- Zone ID changes are runtime-configurable and do not require rebuilding the APK; only Mediation App ID changes require a new Android build.

== 4.14.7 ==
- Tapsell Mediation is now the primary Free post-connect ad when enabled; BlueVPN Story is fallback only.
- Fixes the previous story-first logic that could prevent Tapsell from ever being requested.
- Adds initialization timeout fallback so a delayed Mediation initialization callback cannot silently block ad requests forever.
- No-fill/init/request/show errors fall back to the first-party story while VPN remains connected.

== 4.14.6 ==
- Migrates Android advertising from deprecated Tapsell Plus to native Tapsell Mediation 1.4.0-alpha03.
- Uses native requestInterstitialAd/showInterstitialAd callbacks without reflection.
- Adds Mediation App ID to BlueVPN Manager and stamps it into full GitHub Android builds.
- Advertising stays Free-only, fail-open and independent from VPN connection state.
- Adds runtime diagnostics for initialization, request, no-fill and show errors.

== 4.14.5 ==
- All customer-facing active profile names use BlueVPN branding for both Free and Premium.
- Android VPN notification no longer exposes Telegram channel, bot, provider or imported config remarks.
- Public labels use BlueVPN + plan tier + detected country while raw remarks stay internal for routing/location detection.
- Notification patch now hard-fails the Android preparation step if a future v2rayNG source change could reintroduce raw-name leakage.

== 4.14.4 ==
- Fixes the remaining one-second Locations redraw at the source: candidate reload no longer renders before comparing structural membership.
- Runtime ping/test state is excluded from the structural fingerprint, so Premium and Free lists stay stable while health changes.
- Noisy list broadcasts use a quiet-window debounce and ping broadcasts never invalidate the location pool.
- Cancels stale chunk-render jobs to prevent UI allocation buildup and hardens vendor network callbacks against process crashes.

== 4.14.3 ==
- Free connection is finalized before first-party story advertising starts.
- Ad abort, media failure, CTA navigation and Activity backgrounding can no longer stop/restart VPN.
- Tapsell remains a non-blocking fallback when first-party story media is unavailable.
- Advertising no longer clears connected state or downgrades connection verification.

== 4.14.2 ==
- Stops location-list rebuilds when list/test broadcasts do not change location membership.
- Preserves location scroll position across legitimate structural redraws.
- Removes destructive VPN restart from ConnectivityManager network callbacks.
- Keeps reconnect ownership inside the active connection engine/state machine.

== 4.14.1 ==
- Fixes GitHub Actions repository-hygiene regression gate when CI creates runtime reports/logs after checkout.
- Hygiene gate now validates .gitignore tracking policy instead of rejecting legitimate CI artifact directories.

== 4.14.0 ==
- Full platform deploys keep GitHub as an authoritative clean mirror.
- Generated reports, caches, duplicate root readmes and obsolete tracked files are removed automatically.
- Manager-only deploys never perform repository-wide cleanup.

== 4.13.10 ==
- Location ping/test broadcasts no longer rebuild the whole location list.
- Visible health labels update in-place, preserving touch targets, scroll position and manual selection.
- Adds a regression gate preventing per-ping removeAllViews/render loops.

== 4.13.9 ==
- Home idle/error refresh throttled; no constant one-second redraw loop.
- Free countdown updates every second only while a timed Free session is active.
- Network recovery no longer restarts failed/unverified WARP sessions.

== 4.13.8 ==
- Full cross-component release audit for Android, BlueVPN Manager and BlueVPN Site.
- Adds aggregate guards for Android overlay completeness, RuntimeAudit enum references and network-recovery API wiring.
- Adds release metadata synchronization and site version consistency gates.

== 4.13.7 ==
- Fixes BlueVpnNetworkRecoveryManager enum/API compile errors.
- Uses the existing NETWORK_CHANGE audit event with lost/available details.
- Adds debounced requestNetworkRecovery() to the WARP keepalive service.

== 4.13.6 ==
- Fixes Android compile failure: BlueVpnNetworkRecoveryManager is now imported by BlueVpnHomeActivity.
- prepare_android.py now copies BlueVpnNetworkRecoveryManager.kt into the pinned v2rayNG source tree.

== 4.13.5 ==
- Removes all remaining hardcoded 4.12.8/41208 release pins from shipped regression tests.
- Historical feature tests now validate current release metadata instead of freezing an old app version.
- Prevents repeated one-by-one CI failures after future version bumps.

== 4.13.4 ==
- Removes stale hardcoded 4.12.8 Fast CI release expectations.
- Fast CI now validates current branding/release synchronization dynamically.

== 4.13.3 ==
- Removes stale hardcoded 4.12.8 expectations from the Elementor regression test.
- Regression now checks release/branding synchronization and deterministic version_code.

== 4.13.2 ==
- Restores the exact flat-ZIP resolver compatibility path expected by the regression gate.
- Keeps the 4.13.1 manager-only and wrapped-ZIP root resolver fixes intact.

== 4.13.1 ==
- Fix manager-only ZIP root detection after single-directory extraction collapse.
- Resolve BlueVPN roots from sentinel files instead of a fixed nesting-depth scan.
- Preserve bluevpn-manager/ path when deploying manager-only packages.

== 4.13.0 ==
- Telegram ZIP downloads now use direct streaming with byte-length verification.
- Detects truncated Telegram/CDN responses before ZipArchive parsing.
- Five bounded retries and cURL-first transport for cPanel/PHP hosts.

== 4.12.10 ==
- GitHub delta-only deployment to reduce Git Data API traffic.
- Shared transient retry handling for GitHub blobs/trees/commits/refs.
- Readme and plugin release metadata synchronized for GitHub Actions.

== 4.12.8 ==
* ویدئوی استوری اتصال رایگان در اندروید دیگر صرفاً با آماده‌شدن صدا Ready محسوب نمی‌شود؛ اولین فریم واقعی ویدئو باید Render شود.
* ویدئوی کوتاه تبلیغ قبل از پخش در Cache محلی دریافت می‌شود تا مشکلات Range/MIME/streaming هاست باعث صفحه سیاه نشود.
* Player از Surface صریح TextureView استفاده می‌کند و اگر فریم ویدئو نیاید، تبلیغ Fail-open می‌شود تا کاربر روی صفحه سیاه گیر نکند.
* پنل تبلیغات برای آپلود جدید، MP4 با H.264/AVC + AAC را به‌عنوان فرمت سازگار با Android الزام/راهنمایی می‌کند.
* پوسته همراه پروژه به BlueVPN Site 1.3.14 همگام شد.

== 4.12.4 ==
* زمان پیام‌های پشتیبانی در اندروید از UTC دیتابیس به Asia/Tehran تبدیل می‌شود و دیگر ساعت خام سرور نمایش داده نمی‌شود.
* Parser مشترک اندروید اکنون timestampهای MySQL با قالب yyyy-MM-dd HH:mm:ss را نیز به‌عنوان UTC می‌خواند.
* اصلاحات Kotlin نسخه 4.11.9 بدون تغییر حفظ شده‌اند.

== 4.11.8 ==
* Support: انتخاب درخواست سه‌مرحله‌ای «بخش → موضوع → پیام» و رفع باگ ازبین‌رفتن انتخاب موضوع.
* Support: Retry امن و idempotent برای ساخت گفتگو و ارسال پیام؛ جلوگیری از ایجاد گفتگو/پیام تکراری هنگام timeout.
* Support: انتقال گفتگو بین بخش/موضوع/اپراتور با اعتبارسنجی سمت سرور و فیلتر UI.
* Support: Topic schema 1.2.0، موضوع‌های پیش‌فرض، اولویت موضوع و chooser اسکرول‌پذیر.
* GuardCore: اسکن اشتراک‌های گمشده اکنون API GuardCore را مانند PasarGuard و Marzban بررسی می‌کند.
* GuardCore: Mapping گمشده با تطبیق سخت‌گیرانه بازیابی و Serviceهای پلن بدون تغییر حجم/انقضا همگام می‌شوند.
* Release hardening: PHP/Regression/manifest gates برای انتشار نهایی سخت‌گیرانه‌تر شدند.

== 4.11.7 ==
* Build pipeline: manual Android builds now default to Fast CI while production repository-dispatch builds remain Full.
* Build cache: pinned Aether, libhevtun and libv2ray artifacts are reused across compatible Android builds.
* Android build: compile and assemble run in one Gradle invocation; signed Fast artifacts are uploaded before production WordPress convergence.
* Existing provider group/inbound selection and paid provisioning behavior from 4.7.5 remains unchanged.

== 4.7.4 ==
* Fix: PasarGuard/Marzban/GuardCore panels can be deleted safely from Control Center.
* Fix: legacy plans without provider IDs automatically use active PasarGuard/Marzban during paid/manual provisioning.
* Fix: manual GuardCore Global Subscription is automatically attached to paid users when no explicit GuardCore route exists.
* Fix: Provider repair scans legacy active subscriptions even when plan provider IDs are empty.

== 4.7.3 ==
* Cloudflare Endpoint Racing روی رنج‌ها و پورت‌های معتبر WARP اضافه شد.
* آخرین Edge سالم برای هر نوع شبکه ذخیره می‌شود و Endpoint خراب cooldown می‌گیرد.
* Turbo scan و WireGuard fallback به‌صورت پیش‌فرض فعال شدند.
* WARP exit guard نسخه 4.6.8 همچنان فعال است و خروجی IR را رد می‌کند.

== 4.6.8 ==
= WARP exit guard: rejects blocked egress countries (IR by default) and fails over to the configured Free pool. =

== 4.6.7 ==
* Free WARP entitlement is independent from legacy free-subscription availability.
* Adds WARP/Pool engine policy controls to WordPress and schema 1.11.0.

== 4.6.4 ==
= Pool sync single-owner / nonblocking locations =

== 4.6.0 ==
* رفع خطای Kotlin در BlueVpnHomeActivity هنگام ارسال Live heartbeat داخل withContext؛ Activity Context به‌صورت صریح به BlueVpnLiveReporter داده می‌شود.
* تمام قابلیت‌های BlueAI Subscription Pool Orchestrator، تفکیک دائمی Free/Premium و Live Telemetry نسخه‌های قبل حفظ شده‌اند.
* این Hotfix تغییری در API، Schema یا مسیر v2rayNG/Xray ایجاد نمی‌کند.


== 4.4.6 ==
* دریافت Beta به نشست واقعی حساب متصل شد؛ Access Token در صورت نیاز با Refresh Token بازسازی می‌شود.
* بررسی دستی Beta پس از Sync پس‌زمینه، metadata را دوباره می‌خواند تا Release تازه از دست نرود.
* Cache کانال بروزرسانی در مرزهای Login/Logout/Invalid Session پاک می‌شود.
* diagnostic احراز هویت release در mobile/config اضافه شد.

== تغییرات 4.4.1 ==
* جایگذاری هوشمند پترن‌ها اضافه شد؛ BlueVPN بر اساس متن، عنوان و قرارداد متغیرهای هر پیام بهترین پترن IranPayamak را پیشنهاد و روی خانه‌های خالی تنظیم می‌کند.
* انتخاب‌های دستی معتبر حفظ می‌شوند و فقط دکمه «بازچینی کامل» با تأیید مدیر اجازه جایگزینی آن‌ها را دارد.
* پترن دارای متغیر ناسازگار هرگز خودکار متصل نمی‌شود تا attributes اشتباه به Provider ارسال نشود.
* بعد از «تازه‌سازی پترن‌ها» جایگذاری هوشمند خالی‌ها خودکار اجرا می‌شود و گزارش اطمینان/موارد مبهم در پنل نمایش داده می‌شود.


کنترل‌پلین اصلی BlueVPN روی WordPress/MySQL.

== تغییرات 4.3.10 ==
* اتصال SMS/OTP با مستندات رسمی FarazSMS / IranPayamak همگام شد.
* فهرست پترن‌ها از `GET /ws/v1/patterns` بدون body و بدون فیلتر `share=1` دریافت می‌شود تا پترن‌های خصوصی حساب اشتباهاً حذف نشوند.
* وضعیت Active در BlueVPN فیلتر می‌شود و اگر فهرست خالی باشد، پترن تنظیم‌شده با `GET /patterns/{code}` به‌صورت دقیق بازیابی/اعتبارسنجی می‌شود.
* پاسخ‌های Provider با `status=error/failed` حتی در HTTP 2xx خطا محسوب می‌شوند.

== تغییرات 4.3.8 ==
- برای بنرها و استوری‌های تبلیغاتی «عملکرد هنگام لمس» اضافه شد: ورود/ثبت‌نام، مشاهده پلن‌ها، خرید اشتراک، حساب کاربری، تمدید/ارتقا، تنظیمات یا لینک خارجی.
- مسیرهای داخلی به‌صورت allow-list و با قرارداد `bluevpn://...` ساخته می‌شوند؛ پنل نمی‌تواند Intent یا Activity دلخواه به Android تزریق کند.
- برای «خرید اشتراک» می‌توان یک پلن مشخص تعیین کرد؛ کاربر مهمان ابتدا احراز هویت می‌شود و پس از ورود همان پلن در ابتدای فهرست با Highlight نمایش داده می‌شود.
- URL وب اختیاری به‌عنوان fallback نگه داشته می‌شود تا نسخه‌های قدیمی اپ همچنان بتوانند مقصد امن http/https را باز کنند.
- لمس CTA در استوری اجباری، اتصال Free درحال آماده‌سازی را قبل از ورود به مسیر خرید متوقف می‌کند و به‌عنوان مشاهده کامل تبلیغ حساب نمی‌شود.

== تغییرات 4.3.7 ==
- بخش «استوری تبلیغاتی اتصال رایگان» به پنل تبلیغات اضافه شد؛ عکس و ویدئو با انتخاب تصادفی پشتیبانی می‌شوند.
- اتصال Free پس از RUNNING شدن Xray تا پایان استوری در حالت Pending می‌ماند؛ تایمر Session و وضعیت Connected فقط پس از پایان تبلیغ ثبت می‌شوند.
- خروج از استوری اجباری با Home/Recent Apps اتصال در حال آماده‌سازی را متوقف می‌کند تا تبلیغ قابل دورزدن نباشد.
- اگر رسانه یا تنظیمات تبلیغ به‌دلیل خطای شبکه/هاست قابل دریافت نباشد، مسیر Fail-open است تا خرابی تبلیغ سرویس رایگان را برای همه قطع نکند.
- اگر استوری First-party نمایش داده شود، Tapsell روی همان اتصال دوباره نمایش داده نمی‌شود.

== تغییرات 4.3.6 ==
- قرارداد تبلیغات `/api/v1/mobile/config` اصلاح شد: کلید canonical `advertising` دوباره برای Android برمی‌گردد و `ads` فقط alias سازگاری است.
- تنظیمات Tapsell دوباره در کلید `tapsell` به اپ ارسال می‌شود؛ Regression ایجادشده هنگام بازنویسی Release Channels حذف شد.
- Android هم `advertising` و هم `ads` را می‌پذیرد تا هنگام بروزرسانی جداگانه Manager و APK، بنرها ناپدید نشوند.
- این اصلاح فقط Control Plane/تبلیغات است و Runtime رسمی v2rayNG/Xray تغییر نکرده است.

== قابلیت‌های جاری ==
- احراز هویت، حساب، پلن، سفارش، دستگاه و Session روی MySQL.
- Providerهای PasarGuard، Marzban و GuardCore و Subscription Bridge بومی WordPress.
- BlueAI، تبلیغات، اتصال رایگان، BluPal، OTP و اعلان‌های پیامکی.
- بروزرسانی خودکار افزونه با کنترل انتشار و پایش سلامت.
- Backup/Restore، Health dashboard و ابزارهای مدیریتی BlueVPN.
- Migration Bridge فقط برای بازیابی/انتقال داده‌های قدیمی و نه به‌عنوان Runtime اصلی.

== قرارداد نسخه 4.1.6 ==
- Free و Premium با Pool Identity مستقل می‌شوند؛ تغییر پلن/Provider حتی با URL یکسان دقیقاً یک Refresh واقعی ایجاد می‌کند.
- Account Sync عادی فقط خواندنی است و Force Sync فقط در عملیات صریح کاربر انجام می‌شود.
- Subscriptionهای مدیریت‌شده auto-update ندارند و تغییر Pool وسط Connect ممنوع است.
- Schema 1.6.0 با ایندکس‌های ترکیبی برای entitlement، session، device، order، webhook و BlueAI.
- انتشار WordPress فقط پس از Build و Sign موفق APK در Workflow اصلی انجام می‌شود.
- نسخه‌گذاری BlueVPN از الگوی x.y.0 تا x.y.10 پیروی می‌کند؛ بعد از x.y.10 نسخه بعدی x.(y+1).0 است.

== Changelog ==

== 4.4.5 ==
* Home: نمایش گرافیکی سرعت دانلود/آپلود واقعی در فضای زیر دکمه اتصال.
* Home: تایمر Free از Header به پنل Telemetry منتقل شد؛ Premium مدت دقیق اتصال را نمایش می‌دهد.
* BlueAI 2.1 / Schema v3: اندازه‌گیری RTT واقعی چندنمونه‌ای از داخل تونل Xray، همراه min/max/jitter/loss.
* Live dashboard: Ping صفر/تخمینی نمایش داده نمی‌شود و Routeهای یادگرفته‌شده از Heartbeat واقعی latency می‌گیرند.



= 4.3.5 =
* Beta Testerها اکنون تمام مسیر بروزرسانی خودکار را مانند Stable دریافت می‌کنند.
* سیاست دانلود خودکار برای Stable و Beta در پنل به‌صورت جداگانه قابل کنترل است.
* Force Update نسخه Beta همچنان per-release است و فقط روی Beta Testerهای مجاز اثر می‌گذارد.
* Android کانال release را ذخیره می‌کند و دیالوگ بروزرسانی Beta را با برچسب آزمایشی نمایش می‌دهد.
* دانلود، SHA validation و PackageInstaller برای Beta و Stable یکسان است.

= 4.3.4 =
* Fixed /mobile/config HTTP 500 caused by stale BlueVPN_Ads helper method names.
* Free session duration and Free policy now refresh independently from local pool readiness.
* A server-side duration reduction clamps an already-active Free session; duration increases apply on the next connection.
* Manual update checks also persist the Free policy returned by WordPress.
= 4.3.3 =
* Update checker is cache-first: manual refresh no longer blocks on GitHub API.
* Release sync is queued in the background and mobile config falls back to the last Stable metadata if release-channel lookup fails.
* BlueAI dashboard labels AI Schema v1 clients as legacy instead of implying they support real Live Heartbeat.
= 4.3.2 =
* BlueAI Engine v2 با یادگیری مستقل Free/Premium و پایش Live همزمان.
* Heartbeat برای کاربران مهمان/رایگان نیز فعال شد؛ بدون جمع‌آوری محتوای ترافیک یا IP مقصد.
* داشبورد Live، سلامت نسخه‌ها، Schema versioning و Capability negotiation به پنل افزوده شد.
* دانش نسخه‌های قبلی حفظ می‌شود و به‌عنوان cold-start fallback کم‌وزن استفاده می‌شود.
= 4.3.1 =
* Added server-controlled Beta/Stable release channels.
* New GitHub APK releases default to Beta and are hidden from normal users.
* Added per-customer Beta Tester flag and per-release force/stop/promote controls.
* Stable promotion reuses the exact APK/SHA without rebuilding.
* Android update checks now authenticate with the current app session so WordPress can select the correct release channel.

= 4.3.0 =
- رفع بنر خالی: تصویر تا Decode موفق در Android نمایش داده نمی‌شود و در تعویض اسلاید تصویر قبلی تا آماده‌شدن بعدی حفظ می‌شود.
- تصاویر تبلیغات MySQL به‌صورت lazy در wp-content/uploads/bluevpn-ads به فایل استاتیک تبدیل می‌شوند؛ REST باینری فقط fallback است.
- Content-Length باینری PHP حذف و خروجی خام در برابر zlib/output-buffer سخت‌سازی شد.
- تست سلامت تبلیغات حالا خود بایت تصویر را Decode می‌کند، نه فقط HTTP 200/Content-Type.

= 4.1.6 =
- تمام Routeهای مخفی لوکیشن انتخاب‌شده تا آخرین گزینه در Failover قابل استفاده‌اند؛ AUTO به‌صورت Batch پیش‌رونده عمل می‌کند و دیگر رتبه‌های بعد از 5/10/18 حذف نمی‌شوند.
- پنجره Start هسته Xray برای Cold Start به 24 ثانیه افزایش یافت و آماده‌شدن Local Proxy تا 5 ثانیه فرصت دارد.
- تأیید اینترنت با چند endpoint و معیار پاسخ واقعی 2xx/3xx/4xx انجام می‌شود تا تغییر پاسخ Cloudflare/Google یا Health endpoint یک VPN سالم را خراب اعلام نکند.
- HTTP local proxy با احراز هویت Basic و SOCKS fallback پشتیبانی می‌شود؛ Dynamic SOCKS در BlueVPN خاموش است تا پروسه UI و CoreVpnService روی یک پورت قطعی باشند.
- Fingerprint کانفیگ، Browser Dialer، Proxy Chain و Policy Group را هم لحاظ می‌کند تا کانفیگ‌های واقعاً متفاوت اشتباهی Deduplicate نشوند.

= 4.1.5 =
- کارت انتخاب لوکیشن و خلاصه وضعیت اتصال در یک کارت واحد ادغام شدند و کارت جداگانه پایین آن حذف شد.
- هیچ کارت/دکمه/Activity با عنوان BlueAI در رابط عمومی Android نمایش داده نمی‌شود؛ موتور هوشمند فقط در پس‌زمینه برای رتبه‌بندی، Failover و پایش کیفیت کار می‌کند.
- وضعیت قابل‌نمایش کاربر فقط شامل لوکیشن، آمادگی/اتصال و نتیجه کلی مسیر است؛ جزئیات داخلی Route و AI همچنان مخفی هستند.

= 4.1.4 =
- خلاصه BlueAI در صفحه اصلی با GUID/لوکیشن انتخاب‌شده همگام شد تا نتیجه قدیمی یک کشور دیگر نمایش داده نشود.
- اطلاعات داخلی Route مانند امتیاز، پینگ نامشخص و تعداد خطاهای پیاپی از UI عمومی حذف شد و فقط در موتور رتبه‌بندی باقی می‌ماند.
- نمایش نوع شبکه برای کاربر خواناتر شد و مقدار خام mobile/cellular به «دیتای موبایل» تبدیل می‌شود.

= 4.1.3 =
- قبل از شروع VPN، کانفیگ واقعی هر Route مخفی با سازنده رسمی v2rayNG تولید/اعتبارسنجی می‌شود؛ لوکیشن بدون کانفیگ قابل اجرا وارد اتصال نمی‌شود.
- GUID انتخاب‌شده مستقیماً تا پروسه CoreVpnService/CoreServiceManager منتقل می‌شود تا daemon سرور دیگری را از MMKV انتخاب نکند.
- Profile ناقص یا Import نیمه‌کاره قبل از ساخت TUN رد می‌شود و Failover سراغ Route بعدی همان لوکیشن می‌رود.

= 4.1.2 =
- نمایش فقط لوکیشن‌ها؛ Route/GUID و تعداد مسیرهای داخلی دیگر در رابط کاربر نمایش داده نمی‌شود.
- انتخاب یک لوکیشن، موتور اتصال را به همان کشور محدود می‌کند و بهترین اتصال داخلی به‌صورت خودکار انتخاب می‌شود.
- انتخاب‌های MANUAL_SERVER باقی‌مانده از نسخه‌های قبلی به MANUAL_LOCATION مهاجرت می‌شوند.
- Failover، امتیازدهی، پینگ و سلامت مسیرها همچنان داخلی باقی می‌مانند و بدون نمایش جزئیات به کاربر اجرا می‌شوند.

= 4.1.1 =
- رفع Race پایان Failover که پس از شکست آخرین مسیر، UI را دوباره وارد «در حال اتصال/شناسایی» می‌کرد.
- جلوگیری از قبول Ping دیررس پس از خطای نهایی و جلوگیری از زنده‌شدن دوباره Verification.
- نگه‌داشتن Runtime Gate تا دریافت توقف واقعی CoreVpnService و جلوگیری از تغییر Subscription در زمان آزادسازی Xray.
- نمایش علت واقعی شکست آخرین Candidate به‌جای خطای عمومی و مبهم.
- همگام‌سازی Runtime با v2rayNG 2.2.6؛ AndroidLibXrayLite از ساب‌ماژول رسمی resolve می‌شود و برچسب آن با نسخه Xray-core اشتباه گرفته نمی‌شود.

= 4.1.0 =
- سخت‌سازی جداسازی Free/Premium و حذف Refreshهای ناخواسته.
- رفع انتظار نامحدود Connection Gate و سخت‌سازی چرخه Connect/Disconnect/Switch.
- جداسازی BlueAI از Repair/Import اشتراک و اجرای Local-first scoring.
- ارتقای دیتابیس به Schema 1.6.0 و بهینه‌سازی Queryهای پرتکرار.
- پاک‌سازی CI، حذف snapshotهای تولیدی/تاریخی و یکپارچه‌سازی Release Gate.

== 4.4.4 ==
* PasarGuard: گروه‌های فعال به‌صورت Live نمایش داده می‌شوند؛ انتخاب per-plan اعمال می‌شود و انتخاب خالی یعنی همه گروه‌های فعال.
* Marzban: Inboundهای فعال به‌صورت Live نمایش داده می‌شوند؛ انتخاب per-plan ذخیره و در Provision/Repair اعمال می‌شود و انتخاب خالی یعنی همه Inboundهای فعال.
* Repair: کاربران موجود نیز access map خود را دوباره دریافت می‌کنند، بدون تمدید یا Reset مصرف.
* Hardening: proxy_settings خالی/لیستی دیگر باعث HTTP 422 در PasarGuard/Marzban نمی‌شود.
