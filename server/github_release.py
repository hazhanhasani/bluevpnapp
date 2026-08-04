from __future__ import annotations

import asyncio
import json
import os
import re
import time
from typing import Any

import httpx


DEFAULT_REPOSITORY = "hazhanhasani/bluevpnapp"
CACHE_TTL_SECONDS = 15
ERROR_CACHE_TTL_SECONDS = 10

_cache: dict[str, Any] = {
    "repository": "",
    "expires_at": 0.0,
    "value": None,
    "error": "",
}


def github_repository() -> str:
    value = (
        os.getenv("GITHUB_RELEASE_REPOSITORY")
        or os.getenv("GITHUB_REPOSITORY")
        or DEFAULT_REPOSITORY
    ).strip()

    if value.startswith("https://github.com/"):
        value = value.removeprefix(
            "https://github.com/"
        )
    value = value.removesuffix(".git").strip("/")

    if not re.fullmatch(
        r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+",
        value,
    ):
        return DEFAULT_REPOSITORY
    return value


def _headers(authenticated: bool = True) -> dict[str, str]:
    headers = {
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "BlueVPN-Update-Service/2.1.1",
    }

    token = os.getenv("GITHUB_TOKEN", "").strip()
    if authenticated and token:
        headers["Authorization"] = f"Bearer {token}"

    return headers


async def _get_response(
    client: httpx.AsyncClient,
    url: str,
) -> httpx.Response:
    response = await client.get(
        url,
        headers=_headers(True),
    )

    # A restricted/expired PAT must not break a public repository lookup.
    if (
        response.status_code in {401, 403}
        and "Authorization" in _headers(True)
    ):
        response = await client.get(
            url,
            headers=_headers(False),
        )

    return response


def _clean_version(value: str) -> str:
    match = re.search(
        r"(?<!\d)(\d+\.\d+\.\d+)(?!\d)",
        value or "",
    )
    return match.group(1) if match else "0.0.0"


def _fallback_version_code(version: str) -> int:
    parts = [
        int(item)
        for item in re.findall(r"\d+", version)
    ]
    if len(parts) >= 3:
        major, minor, patch = parts[:3]
        # BlueVPN version families use predictable monotonic codes.
        if major == 1 and minor == 0:
            return 10_000 + patch
        if major == 2 and minor == 0:
            return 20_000 + patch
        if major == 2 and minor == 1:
            return 21_000 + patch
        return (
            major * 1_000_000
            + minor * 10_000
            + patch
        )
    return 0


def _asset_architecture(name: str) -> str:
    lower = name.lower()

    if "arm64-v8a" in lower or "arm64" in lower:
        return "arm64-v8a"
    if (
        "armeabi-v7a" in lower
        or "armeabi" in lower
        or "v7a" in lower
    ):
        return "armeabi-v7a"
    if "universal" in lower or "all" in lower:
        return "universal"
    return "other"


def _release_assets(
    payload: dict[str, Any],
) -> tuple[dict[str, str], str, str]:
    assets: dict[str, str] = {}
    manifest_url = ""
    checksums_url = ""

    for item in payload.get("assets") or []:
        if not isinstance(item, dict):
            continue

        name = str(item.get("name") or "")
        url = str(item.get("browser_download_url") or "")
        lower = name.lower()

        if not url:
            continue
        if lower == "release-manifest.json":
            manifest_url = url
            continue
        if lower == "sha256sums.txt":
            checksums_url = url
            continue
        if not lower.endswith(".apk"):
            continue

        arch = _asset_architecture(name)
        if arch not in assets:
            assets[arch] = url

    return assets, manifest_url, checksums_url


def parse_release_payload(
    payload: dict[str, Any],
    manifest: dict[str, Any] | None = None,
) -> dict[str, Any]:
    manifest = (
        manifest
        if isinstance(manifest, dict)
        else {}
    )

    version = _clean_version(
        str(
            manifest.get("version")
            or payload.get("tag_name")
            or payload.get("name")
            or ""
        )
    )

    version_code = int(
        manifest.get("version_code")
        or _fallback_version_code(version)
    )

    assets, manifest_url, checksums_url = (
        _release_assets(payload)
    )

    preferred_url = (
        assets.get("arm64-v8a")
        or assets.get("universal")
        or assets.get("armeabi-v7a")
        or assets.get("other")
        or ""
    )

    body = str(
        manifest.get("update_message")
        or payload.get("body")
        or "نسخه جدید BlueVPN در GitHub آماده است."
    ).strip()

    if len(body) > 1200:
        body = body[:1197].rstrip() + "…"

    return {
        "source": "github_release",
        "repository": github_repository(),
        "version": version,
        "version_code": version_code,
        "title": str(
            manifest.get("update_title")
            or payload.get("name")
            or f"BlueVPN {version}"
        ).strip(),
        "message": body,
        "apk_url": preferred_url,
        "apk_assets": assets,
        "release_url": str(
            payload.get("html_url") or ""
        ),
        "manifest_url": manifest_url,
        "checksums_url": checksums_url,
        "published_at": str(
            payload.get("published_at") or ""
        ),
        "build_number": int(
            manifest.get("build_number") or 0
        ),
        "commit": str(
            manifest.get("commit")
            or payload.get("target_commitish")
            or ""
        ),
        "asset_count": len(assets),
    }


async def _download_manifest(
    client: httpx.AsyncClient,
    url: str,
) -> dict[str, Any]:
    if not url:
        return {}

    response = await _get_response(client, url)
    if response.status_code != 200:
        return {}

    try:
        value = response.json()
    except Exception:
        try:
            value = json.loads(response.text)
        except Exception:
            return {}

    return value if isinstance(value, dict) else {}


async def _fetch_latest_release() -> dict[str, Any]:
    repository = github_repository()
    api_url = (
        "https://api.github.com/repos/"
        f"{repository}/releases/latest"
    )

    async with httpx.AsyncClient(
        timeout=15,
        follow_redirects=True,
    ) as client:
        response = await _get_response(
            client,
            api_url,
        )

        if response.status_code == 404:
            raise RuntimeError(
                "هنوز GitHub Release منتشر نشده است"
            )

        if response.status_code != 200:
            raise RuntimeError(
                "خواندن آخرین Release گیت‌هاب ناموفق بود: "
                f"HTTP {response.status_code}"
            )

        payload = response.json()
        if not isinstance(payload, dict):
            raise RuntimeError(
                "پاسخ Release گیت‌هاب نامعتبر است"
            )

        _, manifest_url, _ = _release_assets(payload)
        manifest = await _download_manifest(
            client,
            manifest_url,
        )

    return parse_release_payload(
        payload,
        manifest,
    )


async def latest_github_release(
    force: bool = False,
) -> tuple[dict[str, Any] | None, str]:
    repository = github_repository()
    now = time.monotonic()

    if (
        not force
        and _cache["repository"] == repository
        and now < float(_cache["expires_at"])
    ):
        return _cache["value"], str(_cache["error"])

    try:
        value = await _fetch_latest_release()
        _cache.update(
            {
                "repository": repository,
                "expires_at": now + CACHE_TTL_SECONDS,
                "value": value,
                "error": "",
            }
        )
        return value, ""
    except Exception as exc:
        error = str(exc)

        # Preserve the last successful Release during temporary GitHub errors.
        last_value = (
            _cache["value"]
            if _cache["repository"] == repository
            else None
        )

        _cache.update(
            {
                "repository": repository,
                "expires_at": now + ERROR_CACHE_TTL_SECONDS,
                "value": last_value,
                "error": error,
            }
        )
        return last_value, error


def clear_github_release_cache() -> None:
    _cache.update(
        {
            "repository": "",
            "expires_at": 0.0,
            "value": None,
            "error": "",
        }
    )
