from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding='utf-8')


def require(ok: bool, message: str) -> None:
    if not ok:
        raise SystemExit('FAIL: ' + message)

plugin = text('bluevpn-manager/bluevpn-manager.php')
updater = text('bluevpn-manager/includes/class-bluevpn-github-updater.php')
bot = text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
api = text('bluevpn-manager/includes/class-bluevpn-api.php')
build = text('.github/workflows/build-apk.yml')

checks = [
    ('Version: 4.0.32' in plugin, 'manager header must be 4.0.32'),
    ("BLUEVPN_MANAGER_VERSION', '4.0.32'" in plugin, 'manager constant must be 4.0.32'),
    ('github_token_for_internal_requests' in bot, 'bot must expose internal-only GitHub token accessor'),
    ('BlueVPN_Telegram_Bot::github_token_for_internal_requests()' in updater, 'updater must reuse migrated GitHub token'),
    ("'Authorization'] = 'Bearer ' . $token" in updater, 'updater must authenticate GitHub HTTP requests'),
    ("'Accept' => $binary ? 'application/octet-stream'" in updater, 'binary release asset requests must use octet-stream'),
    ("'asset_api_url' => $assetApiUrl" in updater, 'release asset API URL must be retained'),
    ("'package' => $authenticatedAsset ? $assetApiUrl : $browserDownloadUrl" in updater, 'authenticated updater must use asset API package URL'),
    ("'github_updater' => $updater" in api, 'health endpoint must expose safe updater diagnostics'),
    ('WORDPRESS_AUTOUPDATE_TIMEOUT' in build, 'build must report WordPress timeout explicitly'),
    ('WORDPRESS_BOOTSTRAP_REQUIRED' in build, 'build must report one-time bootstrap requirement'),
    ('WORDPRESS_UPDATER_MESSAGE' in build, 'build must report updater message'),
    ('updater_auth=${LAST_UPDATER_AUTH}' in build, 'build must include updater auth state'),
    ('git-push-final' in build, 'only final push failure should retain raw git error output'),
    ('::error::|' in build, 'Telegram reporter must capture GitHub Actions error annotations'),
]

for ok, message in checks:
    require(ok, message)

print(f'OK: authenticated WordPress updater validated ({len(checks)} checks)')
