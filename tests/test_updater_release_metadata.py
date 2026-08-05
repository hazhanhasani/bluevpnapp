from server.github_release import parse_release_payload


def _payload():
    return {
        "tag_name": "v3.0.13",
        "name": "BlueVPN 3.0.13",
        "html_url": "https://github.com/example/bluevpn/releases/tag/v3.0.13",
        "assets": [
            {
                "name": "BlueVPN_3.0.13_arm64-v8a.apk",
                "browser_download_url": "https://example.invalid/arm64.apk",
                "size": 29_110_015,
                "digest": "sha256:" + "a" * 64,
                "content_type": "application/vnd.android.package-archive",
            },
            {
                "name": "BlueVPN_3.0.13_armeabi-v7a.apk",
                "browser_download_url": "https://example.invalid/v7.apk",
                "size": 29_400_000,
                "digest": "sha256:" + "b" * 64,
                "content_type": "application/vnd.android.package-archive",
            },
            {
                "name": "release-manifest.json",
                "browser_download_url": "https://example.invalid/release-manifest.json",
            },
        ],
    }


def test_release_parser_exposes_apk_integrity_metadata():
    parsed = parse_release_payload(
        _payload(),
        {"version": "3.0.13", "version_code": 30013},
    )

    assert parsed["apk_assets"]["arm64-v8a"].endswith("arm64.apk")
    assert parsed["apk_asset_meta"]["arm64-v8a"]["sha256"] == "a" * 64
    assert parsed["apk_asset_meta"]["arm64-v8a"]["size"] == 29_110_015
    assert parsed["apk_asset_meta"]["armeabi-v7a"]["sha256"] == "b" * 64


def test_release_parser_rejects_malformed_digest():
    payload = _payload()
    payload["assets"][0]["digest"] = "sha256:not-a-real-hash"
    parsed = parse_release_payload(payload)
    assert parsed["apk_asset_meta"]["arm64-v8a"]["sha256"] == ""
