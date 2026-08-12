from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path):
    return (ROOT / path).read_text(encoding='utf-8')


def test_manager_version_and_schema_contract():
    plugin = text('bluevpn-manager/bluevpn-manager.php')
    app = text('branding/app.json')
    assert '* Version: 4.0.32' in plugin
    assert "BLUEVPN_MANAGER_VERSION', '4.0.32'" in plugin
    assert "BLUEVPN_MANAGER_SCHEMA_VERSION', '1.5.0'" in plugin
    assert '"version_name": "4.0.32"' in app
    assert '"version_code": 40032' in app


def test_updater_reuses_migrated_github_token_without_exposing_it():
    bot = text('bluevpn-manager/includes/class-bluevpn-telegram-bot.php')
    updater = text('bluevpn-manager/includes/class-bluevpn-github-updater.php')
    assert 'github_token_for_internal_requests' in bot
    assert "BlueVPN_Telegram_Bot::github_token_for_internal_requests()" in updater
    assert "'Authorization'] = 'Bearer ' . $token" in updater
    assert "'authenticated' => self::github_token() !== ''" in updater


def test_private_release_asset_download_uses_github_asset_api():
    updater = text('bluevpn-manager/includes/class-bluevpn-github-updater.php')
    assert "'Accept' => $binary ? 'application/octet-stream'" in updater
    assert "releases/assets/" in updater
    assert "'asset_api_url' => $assetApiUrl" in updater
    assert "'authenticated_asset' => $authenticatedAsset" in updater
    assert "'package' => $authenticatedAsset ? $assetApiUrl : $browserDownloadUrl" in updater


def test_health_exposes_safe_updater_diagnostics():
    api = text('bluevpn-manager/includes/class-bluevpn-api.php')
    assert "'github_updater' => $updater" in api
    assert 'BlueVPN_GitHub_Updater::diagnostics()' in api


def test_build_reports_real_wordpress_failure_not_benign_push_retry():
    workflow = text('.github/workflows/build-apk.yml')
    assert 'WORDPRESS_AUTOUPDATE_TIMEOUT' in workflow
    assert 'WORDPRESS_BOOTSTRAP_REQUIRED' in workflow
    assert 'WORDPRESS_UPDATER_MESSAGE' in workflow
    assert 'updater_auth=${LAST_UPDATER_AUTH}' in workflow
    assert 'git-push-final' in workflow
    assert 'Metadata push retry ${attempt}/5' in workflow
    assert '::error::|' in workflow
