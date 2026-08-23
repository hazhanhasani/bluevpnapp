from __future__ import annotations

import base64
import hashlib
import json
import os
import re
import shutil
import xml.etree.ElementTree as ET
from pathlib import Path
from xml.sax.saxutils import escape
from PIL import Image



ROOT = Path(__file__).resolve().parents[1]
CONFIG = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
ANDROID = ROOT / "upstream" / "V2rayNG"
APP = ANDROID / "app"

TAPSELL_MEDIATION_VERSION = "1.4.0-alpha03"
# Official sample App ID: build-safe fallback only. Runtime refuses
# production requests while BLUEVPN_TAPSELL_TEST_FALLBACK is true.
TAPSELL_TEST_APP_ID = "76798342-99a7-4a5f-bf5a-60a088d5dcfb"

# Files below are the upstream runtime compatibility boundary. BlueVPN may call
# these APIs, but prepare_android.py must never rewrite them. This turns the
# architectural rule "v2rayNG owns the runtime" into a build-time invariant.
UPSTREAM_RUNTIME_GUARD = (
    "src/main/java/com/v2ray/ang/core/CoreServiceManager.kt",
    "src/main/java/com/v2ray/ang/core/CoreConfigManager.kt",
    "src/main/java/com/v2ray/ang/service/CoreVpnService.kt",
    "src/main/java/com/v2ray/ang/viewmodel/MainViewModel.kt",
    "src/main/java/com/v2ray/ang/handler/AngConfigManager.kt",
)

def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def snapshot_upstream_runtime() -> dict[str, str]:
    snapshot: dict[str, str] = {}
    for relative in UPSTREAM_RUNTIME_GUARD:
        path = APP / relative
        if not path.exists():
            raise RuntimeError(f"Pinned v2rayNG runtime file is missing: {relative}")
        snapshot[relative] = _sha256(path)
    return snapshot

def assert_upstream_runtime_unchanged(snapshot: dict[str, str]) -> None:
    changed = []
    for relative, expected in snapshot.items():
        path = APP / relative
        actual = _sha256(path) if path.exists() else "missing"
        if actual != expected:
            changed.append(relative)
    if changed:
        raise RuntimeError(
            "BlueVPN overlay modified official v2rayNG runtime files: " +
            ", ".join(changed)
        )
BOOTSTRAP_B64 = "cGFja2FnZSBjb20udjJyYXkuYW5nLmJsdWV2cG4KCmltcG9ydCBhbmRyb2lkLmNvbnRlbnQuQ29udGV4dAoKb2JqZWN0IEJsdWVWcG5Cb290c3RyYXAgewogICAgZnVuIHN0YXJ0KGNvbnRleHQ6IENvbnRleHQpIHsKICAgICAgICB2YWwgYXBwID0gY29udGV4dC5hcHBsaWNhdGlvbkNvbnRleHQKICAgICAgICBCbHVlVnBuVWlHdWFyZC5pbnN0YWxsQ3Jhc2hMb2dnZXIoYXBwKQogICAgICAgIEJsdWVWcG5MaXZlUmVwb3J0ZXIuc3RhcnQoYXBwKQogICAgfQp9Cg=="
BLUEVPN_UPDATE_PATHS_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHBhdGhzIHhtbG5zOmFuZHJvaWQ9Imh0dHA6Ly9zY2hlbWFzLmFuZHJvaWQuY29tL2Fway9yZXMvYW5kcm9pZCI+CiAgICA8ZXh0ZXJuYWwtZmlsZXMtcGF0aAogICAgICAgIG5hbWU9ImJsdWV2cG5fdXBkYXRlcyIKICAgICAgICBwYXRoPSJEb3dubG9hZC8iIC8+CiAgICA8ZmlsZXMtcGF0aAogICAgICAgIG5hbWU9ImJsdWV2cG5faW50ZXJuYWxfdXBkYXRlcyIKICAgICAgICBwYXRoPSJ1cGRhdGVzLyIgLz4KPC9wYXRocz4K"
BLUEVPN_IDS_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHJlc291cmNlcz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX2FjdGlvbl9zZXJ2ZXJzIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fYWN0aW9uX3NldHRpbmdzIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fYWN0aW9uX3N1YnNjcmlwdGlvbiIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX2FjdGl2ZV9yb3V0ZXNfdmFsdWUiIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9haV9jYXJkIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fYWlfc3VtbWFyeSIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX2Nvbm5lY3RfYnV0dG9uIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fZG93bmxvYWRfc3BlZWQiIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9kdXJhdGlvbl92YWx1ZSIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX2hpc3RvcnlfdmFsdWUiIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9sb2NhdGlvbl92YWx1ZSIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX21vZGVfYmFsYW5jZWQiIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9tb2RlX2dhbWluZyIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX21vZGVfc3RyZWFtaW5nIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fbW9kZV92YWx1ZSIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX3BpbmdfdmFsdWUiIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9wcmVtaXVtX2JhZGdlIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fcXVhbGl0eV92YWx1ZSIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX3JlZnJlc2hfc3Vic2NyaXB0aW9uIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fcmVtYWluaW5nX3RpbWUiIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9yZW1haW5pbmdfdm9sdW1lIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fc2VydmVyX2NhcmQiIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9zZXJ2ZXJfbWV0YSIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX3NlcnZlcl9uYW1lIiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fc3RhdHVzX2NhcHRpb24iIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9zdGF0dXNfZG90IiAvPgogICAgPGl0ZW0gdHlwZT0iaWQiIG5hbWU9ImJsdWV2cG5fc3RhdHVzX3RleHQiIC8+CiAgICA8aXRlbSB0eXBlPSJpZCIgbmFtZT0iYmx1ZXZwbl9zdWJzY3JpcHRpb25fc3VtbWFyeSIgLz4KICAgIDxpdGVtIHR5cGU9ImlkIiBuYW1lPSJibHVldnBuX3VwbG9hZF9zcGVlZCIgLz4KPC9yZXNvdXJjZXM+Cg=="
BLUEVPN_SCREEN_BACKGROUND_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPGxheWVyLWxpc3QgeG1sbnM6YW5kcm9pZD0iaHR0cDovL3NjaGVtYXMuYW5kcm9pZC5jb20vYXBrL3Jlcy9hbmRyb2lkIj4KICAgIDxpdGVtPgogICAgICAgIDxzaGFwZT4KICAgICAgICAgICAgPGdyYWRpZW50CiAgICAgICAgICAgICAgICBhbmRyb2lkOmFuZ2xlPSI5MCIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6c3RhcnRDb2xvcj0iI0Y4RkFGRiIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6Y2VudGVyQ29sb3I9IiNGM0Y2RkMiCiAgICAgICAgICAgICAgICBhbmRyb2lkOmVuZENvbG9yPSIjRUVGM0ZCIiAvPgogICAgICAgIDwvc2hhcGU+CiAgICA8L2l0ZW0+CiAgICA8aXRlbSBhbmRyb2lkOmxlZnQ9Ii0xMjBkcCIgYW5kcm9pZDp0b3A9IjYwZHAiIGFuZHJvaWQ6cmlnaHQ9IjEyMGRwIiBhbmRyb2lkOmJvdHRvbT0iMzYwZHAiPgogICAgICAgIDxzaGFwZSBhbmRyb2lkOnNoYXBlPSJvdmFsIj4KICAgICAgICAgICAgPGdyYWRpZW50CiAgICAgICAgICAgICAgICBhbmRyb2lkOnR5cGU9InJhZGlhbCIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6Z3JhZGllbnRSYWRpdXM9IjIyMGRwIgogICAgICAgICAgICAgICAgYW5kcm9pZDpzdGFydENvbG9yPSIjMjAzRjcyRkYiCiAgICAgICAgICAgICAgICBhbmRyb2lkOmNlbnRlckNvbG9yPSIjMEQyQTRDQkEiCiAgICAgICAgICAgICAgICBhbmRyb2lkOmVuZENvbG9yPSIjMDBGNUY4RkUiIC8+CiAgICAgICAgPC9zaGFwZT4KICAgIDwvaXRlbT4KICAgIDxpdGVtIGFuZHJvaWQ6bGVmdD0iMTcwZHAiIGFuZHJvaWQ6dG9wPSIyNTBkcCIgYW5kcm9pZDpyaWdodD0iLTE1MGRwIiBhbmRyb2lkOmJvdHRvbT0iOTBkcCI+CiAgICAgICAgPHNoYXBlIGFuZHJvaWQ6c2hhcGU9Im92YWwiPgogICAgICAgICAgICA8Z3JhZGllbnQKICAgICAgICAgICAgICAgIGFuZHJvaWQ6dHlwZT0icmFkaWFsIgogICAgICAgICAgICAgICAgYW5kcm9pZDpncmFkaWVudFJhZGl1cz0iMTkwZHAiCiAgICAgICAgICAgICAgICBhbmRyb2lkOnN0YXJ0Q29sb3I9IiMxNDcxNUNGRiIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6Y2VudGVyQ29sb3I9IiMwODI1M0Q4QyIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6ZW5kQ29sb3I9IiMwMEY1RjhGRSIgLz4KICAgICAgICA8L3NoYXBlPgogICAgPC9pdGVtPgo8L2xheWVyLWxpc3Q+Cg=="
BLUEVPN_SCREEN_BACKGROUND_NIGHT_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPGxheWVyLWxpc3QgeG1sbnM6YW5kcm9pZD0iaHR0cDovL3NjaGVtYXMuYW5kcm9pZC5jb20vYXBrL3Jlcy9hbmRyb2lkIj4KICAgIDxpdGVtPgogICAgICAgIDxzaGFwZT4KICAgICAgICAgICAgPGdyYWRpZW50CiAgICAgICAgICAgICAgICBhbmRyb2lkOmFuZ2xlPSI5MCIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6c3RhcnRDb2xvcj0iIzA4MDgwQyIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6Y2VudGVyQ29sb3I9IiMwQTBBMEYiCiAgICAgICAgICAgICAgICBhbmRyb2lkOmVuZENvbG9yPSIjMDcwNzBBIiAvPgogICAgICAgIDwvc2hhcGU+CiAgICA8L2l0ZW0+CiAgICA8aXRlbSBhbmRyb2lkOmxlZnQ9Ii0xMjBkcCIgYW5kcm9pZDp0b3A9IjYwZHAiIGFuZHJvaWQ6cmlnaHQ9IjEyMGRwIiBhbmRyb2lkOmJvdHRvbT0iMzYwZHAiPgogICAgICAgIDxzaGFwZSBhbmRyb2lkOnNoYXBlPSJvdmFsIj4KICAgICAgICAgICAgPGdyYWRpZW50CiAgICAgICAgICAgICAgICBhbmRyb2lkOnR5cGU9InJhZGlhbCIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6Z3JhZGllbnRSYWRpdXM9IjIyMGRwIgogICAgICAgICAgICAgICAgYW5kcm9pZDpzdGFydENvbG9yPSIjMjYzRjcyRkYiCiAgICAgICAgICAgICAgICBhbmRyb2lkOmNlbnRlckNvbG9yPSIjMTAyQTRDQkEiCiAgICAgICAgICAgICAgICBhbmRyb2lkOmVuZENvbG9yPSIjMDAwOTA5MEQiIC8+CiAgICAgICAgPC9zaGFwZT4KICAgIDwvaXRlbT4KICAgIDxpdGVtIGFuZHJvaWQ6bGVmdD0iMTcwZHAiIGFuZHJvaWQ6dG9wPSIyNTBkcCIgYW5kcm9pZDpyaWdodD0iLTE1MGRwIiBhbmRyb2lkOmJvdHRvbT0iOTBkcCI+CiAgICAgICAgPHNoYXBlIGFuZHJvaWQ6c2hhcGU9Im92YWwiPgogICAgICAgICAgICA8Z3JhZGllbnQKICAgICAgICAgICAgICAgIGFuZHJvaWQ6dHlwZT0icmFkaWFsIgogICAgICAgICAgICAgICAgYW5kcm9pZDpncmFkaWVudFJhZGl1cz0iMTkwZHAiCiAgICAgICAgICAgICAgICBhbmRyb2lkOnN0YXJ0Q29sb3I9IiMxODNBNjNDNyIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6Y2VudGVyQ29sb3I9IiMwQzI1M0Q4QyIKICAgICAgICAgICAgICAgIGFuZHJvaWQ6ZW5kQ29sb3I9IiMwMDA5MDkwRCIgLz4KICAgICAgICA8L3NoYXBlPgogICAgPC9pdGVtPgo8L2xheWVyLWxpc3Q+Cg=="
BLUEVPN_LOGO_BACKGROUND_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHNoYXBlIHhtbG5zOmFuZHJvaWQ9Imh0dHA6Ly9zY2hlbWFzLmFuZHJvaWQuY29tL2Fway9yZXMvYW5kcm9pZCI+CiAgICA8Z3JhZGllbnQKICAgICAgICBhbmRyb2lkOmFuZ2xlPSIzMTUiCiAgICAgICAgYW5kcm9pZDpzdGFydENvbG9yPSIjNUE5REZGIgogICAgICAgIGFuZHJvaWQ6ZW5kQ29sb3I9IiMxNzZERkYiIC8+CiAgICA8Y29ybmVycyBhbmRyb2lkOnJhZGl1cz0iMTdkcCIgLz4KICAgIDxzdHJva2UgYW5kcm9pZDp3aWR0aD0iMWRwIiBhbmRyb2lkOmNvbG9yPSIjOTFDMkZGIiAvPgo8L3NoYXBlPgo="
BLUEVPN_CONNECT_RING_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHNoYXBlIHhtbG5zOmFuZHJvaWQ9Imh0dHA6Ly9zY2hlbWFzLmFuZHJvaWQuY29tL2Fway9yZXMvYW5kcm9pZCIgYW5kcm9pZDpzaGFwZT0ib3ZhbCI+CiAgICA8Z3JhZGllbnQKICAgICAgICBhbmRyb2lkOnR5cGU9InJhZGlhbCIKICAgICAgICBhbmRyb2lkOmdyYWRpZW50UmFkaXVzPSIxMTBkcCIKICAgICAgICBhbmRyb2lkOnN0YXJ0Q29sb3I9IiMyNzNEN0QiCiAgICAgICAgYW5kcm9pZDpjZW50ZXJDb2xvcj0iIzEyMkM1RiIKICAgICAgICBhbmRyb2lkOmVuZENvbG9yPSIjMEIyMTQ4IiAvPgogICAgPHN0cm9rZSBhbmRyb2lkOndpZHRoPSIyZHAiIGFuZHJvaWQ6Y29sb3I9IiM0QTgyQzciIC8+Cjwvc2hhcGU+Cg=="
BLUEVPN_STATUS_DOT_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHNoYXBlIHhtbG5zOmFuZHJvaWQ9Imh0dHA6Ly9zY2hlbWFzLmFuZHJvaWQuY29tL2Fway9yZXMvYW5kcm9pZCIgYW5kcm9pZDpzaGFwZT0ib3ZhbCI+CiAgICA8c29saWQgYW5kcm9pZDpjb2xvcj0iIzhGQTdDQSIgLz4KPC9zaGFwZT4K"
BLUEVPN_ICON_CHIP_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHNoYXBlIHhtbG5zOmFuZHJvaWQ9Imh0dHA6Ly9zY2hlbWFzLmFuZHJvaWQuY29tL2Fway9yZXMvYW5kcm9pZCI+CiAgICA8Z3JhZGllbnQKICAgICAgICBhbmRyb2lkOmFuZ2xlPSIzMTUiCiAgICAgICAgYW5kcm9pZDpzdGFydENvbG9yPSIjMUI0NjdGIgogICAgICAgIGFuZHJvaWQ6ZW5kQ29sb3I9IiMxNDJFNUEiIC8+CiAgICA8Y29ybmVycyBhbmRyb2lkOnJhZGl1cz0iMTVkcCIgLz4KICAgIDxzdHJva2UgYW5kcm9pZDp3aWR0aD0iMWRwIiBhbmRyb2lkOmNvbG9yPSIjM0M3NUI3IiAvPgo8L3NoYXBlPgo="

BLUEVPN_HOME_THEME_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHJlc291cmNlcz4KICAgIDxzdHlsZSBuYW1lPSJCbHVlVnBuSG9tZVRoZW1lIiBwYXJlbnQ9IkFwcFRoZW1lRGF5TmlnaHQuTm9BY3Rpb25CYXIiPgogICAgICAgIDxpdGVtIG5hbWU9ImFuZHJvaWQ6d2luZG93QmFja2dyb3VuZCI+QGRyYXdhYmxlL2JsdWV2cG5fc2NyZWVuX2JhY2tncm91bmQ8L2l0ZW0+CiAgICAgICAgPGl0ZW0gbmFtZT0iYW5kcm9pZDpzdGF0dXNCYXJDb2xvciI+IzA0MEIxQzwvaXRlbT4KICAgICAgICA8aXRlbSBuYW1lPSJhbmRyb2lkOm5hdmlnYXRpb25CYXJDb2xvciI+IzA0MEIxQzwvaXRlbT4KICAgICAgICA8aXRlbSBuYW1lPSJhbmRyb2lkOndpbmRvd0xpZ2h0U3RhdHVzQmFyIj5mYWxzZTwvaXRlbT4KICAgICAgICA8aXRlbSBuYW1lPSJhbmRyb2lkOndpbmRvd0xpZ2h0TmF2aWdhdGlvbkJhciI+ZmFsc2U8L2l0ZW0+CiAgICAgICAgPGl0ZW0gbmFtZT0iYW5kcm9pZDpmb250RmFtaWx5Ij5zYW5zPC9pdGVtPgogICAgICAgIDxpdGVtIG5hbWU9ImFuZHJvaWQ6d2luZG93QWN0aXZpdHlUcmFuc2l0aW9ucyI+dHJ1ZTwvaXRlbT4KICAgICAgICA8aXRlbSBuYW1lPSJjb2xvclByaW1hcnkiPiMyNDdDRkY8L2l0ZW0+CiAgICAgICAgPGl0ZW0gbmFtZT0iY29sb3JBY2NlbnQiPiM1N0ExRkY8L2l0ZW0+CiAgICA8L3N0eWxlPgo8L3Jlc291cmNlcz4K"


BLUEVPN_FADE_IN_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPGFscGhhIHhtbG5zOmFuZHJvaWQ9Imh0dHA6Ly9zY2hlbWFzLmFuZHJvaWQuY29tL2Fway9yZXMvYW5kcm9pZCIKICAgIGFuZHJvaWQ6ZHVyYXRpb249IjE3MCIKICAgIGFuZHJvaWQ6ZnJvbUFscGhhPSIwLjAiCiAgICBhbmRyb2lkOmludGVycG9sYXRvcj0iQGFuZHJvaWQ6aW50ZXJwb2xhdG9yL2Zhc3Rfb3V0X3Nsb3dfaW4iCiAgICBhbmRyb2lkOnRvQWxwaGE9IjEuMCIgLz4K"
BLUEVPN_FADE_OUT_B64 = "PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPGFscGhhIHhtbG5zOmFuZHJvaWQ9Imh0dHA6Ly9zY2hlbWFzLmFuZHJvaWQuY29tL2Fway9yZXMvYW5kcm9pZCIKICAgIGFuZHJvaWQ6ZHVyYXRpb249IjEzMCIKICAgIGFuZHJvaWQ6ZnJvbUFscGhhPSIxLjAiCiAgICBhbmRyb2lkOmludGVycG9sYXRvcj0iQGFuZHJvaWQ6aW50ZXJwb2xhdG9yL2Zhc3Rfb3V0X2xpbmVhcl9pbiIKICAgIGFuZHJvaWQ6dG9BbHBoYT0iMC4wIiAvPgo="


def patch_tapsell_repository() -> None:
    candidates = (
        ANDROID / "settings.gradle.kts",
        ANDROID / "settings.gradle",
    )
    path = next((candidate for candidate in candidates if candidate.exists()), None)
    if path is None:
        raise RuntimeError("Gradle settings file not found for Tapsell Mediation")

    text = path.read_text(encoding="utf-8")
    if "https://maven.tapsell.ir" in text:
        return

    dependency_block = text.find("dependencyResolutionManagement")
    if dependency_block < 0:
        raise RuntimeError(
            "dependencyResolutionManagement block not found for Tapsell repository"
        )
    repositories_block = text.find("repositories {", dependency_block)
    if repositories_block < 0:
        raise RuntimeError(
            "dependencyResolutionManagement.repositories block not found"
        )

    insertion = repositories_block + len("repositories {")
    if path.suffix == ".kts":
        repo_line = '\n        maven("https://maven.tapsell.ir")'
    else:
        repo_line = '\n        maven { url "https://maven.tapsell.ir" }'

    text = text[:insertion] + repo_line + text[insertion:]
    path.write_text(text, encoding="utf-8")


def patch_build_gradle() -> None:
    path = APP / "build.gradle.kts"
    text = path.read_text(encoding="utf-8")

    text = re.sub(
        r'applicationId\s*=\s*"[^"]+"',
        f'applicationId = "{CONFIG["application_id"]}"',
        text,
        count=1,
    )
    text = re.sub(
        r'versionCode\s*=\s*\d+',
        f'versionCode = {int(CONFIG["version_code"])}',
        text,
        count=1,
    )
    text = re.sub(
        r'versionName\s*=\s*"[^"]+"',
        f'versionName = "{CONFIG["version_name"]}"',
        text,
        count=1,
    )
    text = text.replace(
        'v2rayNG_${variant.versionName}',
        'BlueVPN_${variant.versionName}',
    )

    api_value = CONFIG.get("api_base_url", "").rstrip("/")
    configured_tapsell_app_id = str(CONFIG.get("tapsell_app_id", "")).strip()
    tapsell_app_id = configured_tapsell_app_id or TAPSELL_TEST_APP_ID
    tapsell_test_fallback = configured_tapsell_app_id == ""

    marker = f'applicationId = "{CONFIG["application_id"]}"'
    if marker not in text:
        raise RuntimeError("Android applicationId marker not found")

    fields = (
        (
            "BLUEVPN_API_BASE_URL",
            '\n        buildConfigField("String", "BLUEVPN_API_BASE_URL", "\\"'
            + api_value + '\\"")',
        ),
        (
            "BLUEVPN_TAPSELL_APP_ID",
            '\n        buildConfigField("String", "BLUEVPN_TAPSELL_APP_ID", "\\"'
            + tapsell_app_id + '\\"")',
        ),
        (
            "BLUEVPN_TAPSELL_TEST_FALLBACK",
            '\n        buildConfigField("boolean", "BLUEVPN_TAPSELL_TEST_FALLBACK", "'
            + ("true" if tapsell_test_fallback else "false") + '")',
        ),
        (
            "TapsellMediationAppKey",
            '\n        manifestPlaceholders["TapsellMediationAppKey"] = "'
            + tapsell_app_id + '"',
        ),
    )
    for token, field in fields:
        if token not in text:
            text = text.replace(marker, marker + field, 1)

    dependencies_marker = "dependencies {"
    if dependencies_marker not in text:
        raise RuntimeError("Gradle dependencies block not found")

    # Remove the deprecated Plus SDK if a previous overlay touched the checkout.
    text = re.sub(
        r'\s*implementation\("ir\.tapsell\.plus:tapsell-plus-sdk-android:[^"]+"\)\s*',
        "\n",
        text,
    )

    # Keep the existing BlueVPN compatibility dependencies. WorkManager is used
    # by support/background tasks; explicit Android Guava keeps its public
    # ListenableFuture API resolvable across the v2rayNG/Tapsell dependency graph.
    # Kotlin DSL does not generate type-safe accessors for configurations created
    # dynamically by product flavors at script-compilation time on AGP 9. Use
    # DependencyHandler.add(name, notation) so fdroid-only Tapsell dependencies
    # stay flavor-scoped without causing an unresolved accessor before Gradle can
    # configure the Android plugin.
    required_dependencies = (
        'implementation("com.google.guava:guava:33.6.0-android")',
        f'add("fdroidImplementation", "ir.tapsell:tapsell:{TAPSELL_MEDIATION_VERSION}")',
        f'add("fdroidImplementation", "ir.tapsell.mediation.adapter:legacy:{TAPSELL_MEDIATION_VERSION}")',
        f'add("fdroidImplementation", "ir.tapsell.mediation.adapter:legacy-ima-extension:{TAPSELL_MEDIATION_VERSION}")',
        f'add("fdroidImplementation", "ir.tapsell.mediation.adapter:legacy-taproll:{TAPSELL_MEDIATION_VERSION}")',
        'implementation("com.google.android.gms:play-services-auth-api-phone:18.3.1")',
        'implementation("androidx.work:work-runtime:2.10.0")',
    )
    for dependency in required_dependencies:
        if dependency not in text:
            text = text.replace(
                dependencies_marker,
                dependencies_marker + "\n    " + dependency,
                1,
            )

    # Aether is packaged as a native executable named like a shared library.
    if "useLegacyPackaging = true" not in text:
        android_marker = "android {"
        packaging_block = (
            "\n    packaging {\n"
            "        jniLibs {\n"
            "            useLegacyPackaging = true\n"
            "        }\n"
            "    }\n"
        )
        if android_marker not in text:
            raise RuntimeError("Gradle android block not found")
        text = text.replace(
            android_marker,
            android_marker + packaging_block,
            1,
        )

    path.write_text(text, encoding="utf-8")

    rules_path = APP / "proguard-rules.pro"
    rules = rules_path.read_text(encoding="utf-8") if rules_path.exists() else ""
    tapsell_rules = (
        "\n# BlueVPN Tapsell Mediation integration\n"
        "-keep class ir.tapsell.** { *; }\n"
        "-dontwarn ir.tapsell.**\n"
    )
    if "-keep class ir.tapsell.**" not in rules:
        rules_path.write_text(
            rules.rstrip() + tapsell_rules,
            encoding="utf-8",
        )


def _upsert_android_string(xml_text: str, name: str, value: str) -> str:
    """Replace a string resource if present; otherwise append it once."""
    escaped_value = escape(value)
    pattern = re.compile(
        rf'(<string\s+name="{re.escape(name)}"[^>]*>).*?(</string>)',
        flags=re.DOTALL,
    )

    if pattern.search(xml_text):
        return pattern.sub(
            lambda match: f"{match.group(1)}{escaped_value}{match.group(2)}",
            xml_text,
            count=1,
        )

    closing_tag = "</resources>"
    if closing_tag not in xml_text:
        raise RuntimeError("Invalid Android strings XML: </resources> not found")

    addition = f'    <string name="{name}">{escaped_value}</string>\n'
    return xml_text.replace(closing_tag, addition + closing_tag, 1)


def _android_string_names(xml_text: str) -> set[str]:
    """Return all <string name="..."> resource identifiers in an XML file."""
    return set(
        re.findall(
            r'<string\s+name="([^"]+)"',
            xml_text,
            flags=re.DOTALL,
        )
    )


def _ensure_android_string(xml_text: str, name: str, value: str) -> str:
    """Append a string only when the default locale does not already define it."""
    if name in _android_string_names(xml_text):
        return xml_text
    return _upsert_android_string(xml_text, name, value)


def write_distribution_manifest_overlays() -> None:
    """Keep restricted installer/ad permissions out of the Google Play flavor."""
    fdroid = APP / "src" / "fdroid" / "AndroidManifest.xml"
    play = APP / "src" / "playstore" / "AndroidManifest.xml"
    fdroid.parent.mkdir(parents=True, exist_ok=True)
    play.parent.mkdir(parents=True, exist_ok=True)

    fdroid.write_text(
        """<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <uses-permission android:name="android.permission.REQUEST_INSTALL_PACKAGES" />
    <uses-permission android:name="com.google.android.gms.permission.AD_ID" />
</manifest>
""",
        encoding="utf-8",
    )

    play.write_text(
        """<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android"
    xmlns:tools="http://schemas.android.com/tools">
    <uses-permission android:name="android.permission.REQUEST_INSTALL_PACKAGES" tools:node="remove" />
    <uses-permission android:name="com.google.android.gms.permission.AD_ID" tools:node="remove" />
    <application>
        <activity android:name=".ui.BlueVpnUpdateInstallActivity" tools:node="remove" />
        <provider android:name="com.v2ray.ang.bluevpn.BlueVpnUpdateFileProvider" tools:node="remove" />
        <meta-data android:name="ir.tapsell.mediation.AUTO_INIT" tools:node="remove" />
    </application>
</manifest>
""",
        encoding="utf-8",
    )


def patch_strings() -> None:
    translations = {
        "app_widget_name": "اتصال سریع",
        "app_tile_name": "BlueVPN",
        "connection_connected": "متصل است؛ برای بررسی اتصال لمس کنید",
        "connection_not_connected": "اتصال برقرار نیست",
        "title_sub_setting": "مدیریت اشتراک",
        "title_sub_update": "به‌روزرسانی اشتراک",
        "import_subscription_success": "اشتراک با موفقیت افزوده شد",
        "import_subscription_failure": "افزودن اشتراک ناموفق بود",
        "toast_services_start": "در حال برقراری اتصال امن",
        "toast_services_stop": "اتصال قطع شد",
        "service_started": "اتصال BlueVPN فعال است",
        "service_stopped": "اتصال BlueVPN متوقف شد",
        "notification_service_running": "اتصال امن BlueVPN فعال است",
        "notification_action_stop_v2ray": "توقف",
        "title_service_restart": "راه‌اندازی مجدد",
    }

    # Android Lint requires every translated key to exist in the default
    # locale. Preserve upstream English text when it exists and add a clean
    # BlueVPN fallback only for keys that upstream does not define.
    default_fallbacks = {
        "app_widget_name": "Quick connect",
        "app_tile_name": "BlueVPN",
        "connection_connected": "Connected; tap to check the connection",
        "connection_not_connected": "Not connected",
        "title_sub_setting": "Subscription management",
        "title_sub_update": "Update subscription",
        "import_subscription_success": "Subscription added successfully",
        "import_subscription_failure": "Could not add subscription",
        "toast_services_start": "Establishing a secure connection",
        "toast_services_stop": "Connection stopped",
        "service_started": "BlueVPN connection is active",
        "service_stopped": "BlueVPN connection has stopped",
        "notification_service_running": "BlueVPN secure connection is active",
        "notification_action_stop_v2ray": "Stop",
        "title_service_restart": "Restart",
    }

    default_path = APP / "src/main/res/values/strings.xml"
    default_text = default_path.read_text(encoding="utf-8")
    default_text = _upsert_android_string(
        default_text,
        "app_name",
        CONFIG["app_name"],
    )
    for name, value in default_fallbacks.items():
        default_text = _ensure_android_string(default_text, name, value)
    default_path.write_text(default_text, encoding="utf-8")

    # v2rayNG already contains Persian resources. Edit the existing file
    # instead of defining the same resource names in a second XML file.
    fa_dir = APP / "src/main/res/values-fa"
    fa_dir.mkdir(parents=True, exist_ok=True)
    fa_path = fa_dir / "strings.xml"

    if fa_path.exists():
        fa_text = fa_path.read_text(encoding="utf-8")
    else:
        fa_text = '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n</resources>\n'

    for name, value in translations.items():
        fa_text = _upsert_android_string(fa_text, name, value)

    default_names = _android_string_names(default_text)
    missing_defaults = sorted(set(translations) - default_names)
    if missing_defaults:
        raise RuntimeError(
            "Missing default Android string resources for translated keys: "
            + ", ".join(missing_defaults)
        )

    # Keep all user-facing resource text under the BlueVPN brand. Internal
    # package names and the GPL source notice remain unchanged.
    for old in ("v2rayNG", "V2rayNG", "V2RayNG", "Xray", "XRay"):
        fa_text = fa_text.replace(old, "BlueVPN")
    fa_path.write_text(fa_text, encoding="utf-8")

    for values_dir in APP.glob("src/main/res/values*"):
        candidate = values_dir / "strings.xml"
        if not candidate.exists() or candidate == fa_path:
            continue
        candidate_text = candidate.read_text(encoding="utf-8")
        updated = candidate_text
        for old in ("v2rayNG", "V2rayNG", "V2RayNG", "Xray", "XRay"):
            updated = updated.replace(old, "BlueVPN")
        if updated != candidate_text:
            candidate.write_text(updated, encoding="utf-8")

    # Remove the obsolete file created by the first BlueVPN script.
    duplicate_file = fa_dir / "bluevpn_strings.xml"
    if duplicate_file.exists():
        duplicate_file.unlink()

def _ensure_manifest_permission(
    xml_text: str,
    permission: str,
) -> str:
    if permission in xml_text:
        return xml_text

    opening = re.search(
        r"<manifest\b[^>]*>",
        xml_text,
        flags=re.DOTALL,
    )
    if not opening:
        raise RuntimeError(
            "Invalid AndroidManifest.xml: "
            "<manifest> opening tag not found"
        )

    permission_node = (
        '\n    <uses-permission '
        'android:name="'
        + permission
        + '" />'
    )

    return (
        xml_text[:opening.end()]
        + permission_node
        + xml_text[opening.end():]
    )


def patch_manifest() -> None:
    path = APP / "src/main/AndroidManifest.xml"
    text = path.read_text(encoding="utf-8")

    text = text.replace(
        'android:scheme="v2rayng"',
        f'android:scheme="{CONFIG["deep_link_scheme"]}"',
    )

    launcher_pattern = re.compile(
        r'\s*<activity\s+android:name="\.ui\.MainActivity".*?</activity>',
        flags=re.DOTALL,
    )

    launcher_replacement = (
        '\n        <activity\n'
        '            android:name=".ui.BlueVpnHomeActivity"\n'
        '            android:exported="true"\n'
        '            android:launchMode="singleTask"\n'
        '            android:theme="@style/BlueVpnHomeTheme">\n'
        '            <intent-filter>\n'
        '                <action android:name="android.intent.action.MAIN" />\n'
        '                <category android:name="android.intent.category.LAUNCHER" />\n'
        '                <category android:name="android.intent.category.LEANBACK_LAUNCHER" />\n'
        '            </intent-filter>\n'
        '            <intent-filter>\n'
        '                <action android:name="android.service.quicksettings.action.QS_TILE_PREFERENCES" />\n'
        '            </intent-filter>\n'
        '        </activity>\n\n'
        '        <activity\n'
        '            android:name=".ui.MainActivity"\n'
        '            android:enabled="false"\n'
        '            android:exported="false"\n'
        '            android:excludeFromRecents="true"\n'
        '            android:launchMode="singleTask" />\n\n'
        '        <activity\n'
        '            android:name=".ui.BlueVpnServersActivity"\n'
        '            android:exported="false"\n'
        '            android:theme="@style/BlueVpnHomeTheme" />\n\n'
        '        <activity\n'
        '            android:name=".ui.BlueVpnSubscriptionsActivity"\n'
        '            android:exported="false"\n'
        '            android:theme="@style/BlueVpnHomeTheme" />\n\n'
        '        <activity\n'
        '            android:name=".ui.BlueVpnSettingsActivity"\n'
        '            android:exported="false"\n'
        '            android:theme="@style/BlueVpnHomeTheme" />\n\n'
        '        <activity\n'
        '            android:name=".ui.BlueVpnSupportActivity"\n'
        '            android:exported="false"\n'
        '            android:theme="@style/BlueVpnHomeTheme" />'
    )

    if 'android:name=".ui.BlueVpnHomeActivity"' not in text:
        text, count = launcher_pattern.subn(launcher_replacement, text, count=1)
        if count != 1:
            raise RuntimeError("Could not replace the MainActivity launcher block")

    # BlueVPN owns every customer-facing Android entry point. The upstream
    # widget and Tasker integration are external launcher/plugin surfaces that
    # can invoke upstream activities or expose upstream profile state. BlueVPN
    # does not publish those surfaces, so remove them from the packaged manifest.
    external_upstream_components = (
        r'\s*<receiver\s+android:name="\.receiver\.WidgetProvider".*?</receiver>',
        r'\s*<activity\s+android:name="\.ui\.TaskerActivity".*?</activity>',
        r'\s*<receiver\s+android:name="\.receiver\.TaskerReceiver".*?</receiver>',
    )
    for component_pattern in external_upstream_components:
        text = re.sub(component_pattern, "", text, flags=re.DOTALL)

    text = _ensure_manifest_permission(
        text,
        "android.permission.CHANGE_NETWORK_STATE",
    )
    text = _ensure_manifest_permission(
        text,
        "android.permission.FOREGROUND_SERVICE",
    )
    text = _ensure_manifest_permission(
        text,
        "android.permission.FOREGROUND_SERVICE_SPECIAL_USE",
    )
    text = _ensure_manifest_permission(
        text,
        "android.permission.POST_NOTIFICATIONS",
    )

    if 'android:name="ir.tapsell.mediation.AUTO_INIT"' not in text:
        tapsell_auto_init = (
            '\n        <meta-data\n'
            '            android:name="ir.tapsell.mediation.AUTO_INIT"\n'
            '            android:value="false" />\n'
        )
        text = text.replace(
            "</application>",
            tapsell_auto_init + "    </application>",
            1,
        )

    text = text.replace(
        'android:name="androidx.core.content.FileProvider"',
        'android:name="com.v2ray.ang.bluevpn.BlueVpnUpdateFileProvider"',
    )

    install_activity_name = ".ui.BlueVpnUpdateInstallActivity"
    if install_activity_name not in text:
        install_activity_node = (
            '\n        <activity\n'
            '            android:name=".ui.BlueVpnUpdateInstallActivity"\n'
            '            android:exported="false"\n'
            '            android:excludeFromRecents="true"\n'
            '            android:noHistory="true"\n'
            '            android:launchMode="singleTop"\n'
            '            android:theme="@android:style/Theme.Translucent.NoTitleBar" />\n'
        )
        text = text.replace(
            "</application>",
            install_activity_node + "    </application>",
            1,
        )

    warp_keepalive_name = "com.v2ray.ang.bluevpn.BlueVpnWarpKeepAliveService"
    if warp_keepalive_name not in text:
        service_node = (
            '\n        <service\n'
            '            android:name="com.v2ray.ang.bluevpn.BlueVpnWarpKeepAliveService"\n'
            '            android:enabled="true"\n'
            '            android:exported="false"\n'
            '            android:stopWithTask="false"\n'
            '            android:foregroundServiceType="specialUse">\n'
            '            <property\n'
            '                android:name="android.app.PROPERTY_SPECIAL_USE_FGS_SUBTYPE"\n'
            '                android:value="Maintains the local WARP proxy required by an active BlueVPN tunnel" />\n'
            '        </service>\n'
        )
        text = text.replace("</application>", service_node + "    </application>", 1)

    free_receiver_name = "com.v2ray.ang.bluevpn.BlueVpnFreeSessionReceiver"
    if free_receiver_name not in text:
        receiver_node = (
            '\n        <receiver\n'
            '            android:name="com.v2ray.ang.bluevpn.BlueVpnFreeSessionReceiver"\n'
            '            android:enabled="true"\n'
            '            android:exported="false" />\n'
        )
        text = text.replace(
            "</application>",
            receiver_node + "    </application>",
            1,
        )

    # Replace upstream v2rayNG tile with a BlueVPN-aware tile so Free/WARP
    # also starts/stops its Aether lifecycle rather than only toggling Xray.
    text = text.replace(
        'android:name=".service.QSTileService"',
        'android:name="com.v2ray.ang.bluevpn.BlueVpnQuickTileService"',
    )
    system_receiver = "com.v2ray.ang.bluevpn.BlueVpnSystemActionReceiver"
    if system_receiver not in text:
        receiver_node = (
            '\n        <receiver\n'
            '            android:name="com.v2ray.ang.bluevpn.BlueVpnSystemActionReceiver"\n'
            '            android:enabled="true"\n'
            '            android:exported="false" />\n'
        )
        text = text.replace("</application>", receiver_node + "    </application>", 1)

    provider_authority = (
        "${applicationId}.bluevpn.updateprovider"
    )
    if provider_authority not in text:
        provider_node = (
            '\n        <provider\n'
            '            android:name="com.v2ray.ang.bluevpn.BlueVpnUpdateFileProvider"\n'
            '            android:authorities="${applicationId}.bluevpn.updateprovider"\n'
            '            android:exported="false"\n'
            '            android:grantUriPermissions="true">\n'
            '            <meta-data\n'
            '                android:name="android.support.FILE_PROVIDER_PATHS"\n'
            '                android:resource="@xml/bluevpn_update_paths" />\n'
            '        </provider>\n'
        )
        text = text.replace(
            "</application>",
            provider_node + "    </application>",
            1,
        )

    path.write_text(text, encoding="utf-8")

    try:
        ET.parse(path)
    except ET.ParseError as exc:
        raise RuntimeError(
            f"Generated AndroidManifest.xml is invalid: {exc}"
        ) from exc

    # Do not publish upstream launcher shortcuts. Official v2rayNG shortcuts
    # target ScSwitch/ScScanner/ScStart/ScStop activities, so changing only
    # MainActivity would not close this system surface. The launcher metadata is
    # intentionally absent from BlueVpnHomeActivity. Keep the XML file unused.

    url_scheme_path = APP / "src/main/java/com/v2ray/ang/ui/UrlSchemeActivity.kt"
    url_scheme_text = url_scheme_path.read_text(encoding="utf-8")
    url_scheme_text = url_scheme_text.replace(
        "startActivity(Intent(this, MainActivity::class.java))",
        "startActivity(Intent(this, BlueVpnHomeActivity::class.java))",
    )
    url_scheme_path.write_text(url_scheme_text, encoding="utf-8")

def patch_system_notification() -> None:
    # Public system UI uses BlueVPN branding; raw provider remarks stay internal.
    path = APP / "src/main/java/com/v2ray/ang/handler/NotificationManager.kt"
    text = path.read_text(encoding="utf-8")

    # v2rayNG moved MainActivity between packages across releases. Support both
    # layouts and always route notification taps into BlueVPN Home.
    text = text.replace(
        "import com.v2ray.ang.ui.MainActivity",
        "import com.v2ray.ang.ui.BlueVpnHomeActivity",
    )
    text = text.replace(
        "import com.v2ray.ang.ui.main.MainActivity",
        "import com.v2ray.ang.ui.BlueVpnHomeActivity",
    )
    text = text.replace(
        "Intent(service, MainActivity::class.java)",
        "Intent(service, BlueVpnHomeActivity::class.java)",
    )

    required_imports = (
        "import com.v2ray.ang.bluevpn.BlueVpnPublicProfileName",
        "import com.v2ray.ang.bluevpn.BlueVpnSystemActionReceiver",
        "import com.v2ray.ang.bluevpn.BlueVpnSystemController",
    )
    import_anchor = "import com.v2ray.ang.R"
    if import_anchor not in text:
        raise RuntimeError("NotificationManager R import not found")
    for required_import in required_imports:
        if required_import not in text:
            text = text.replace(
                import_anchor,
                import_anchor + "\n" + required_import,
                1,
            )

    # Never expose upstream/provider/channel remarks in Android system UI.
    # v2rayNG 2.2.6 uses `.setContentTitle(currentConfig?.remarks)`, while
    # older builds used a fallback expression. Match both forms explicitly.
    title_patterns = (
        r'\.setContentTitle\(\s*currentConfig\?\.remarks\s*\)',
        r'\.setContentTitle\(\s*currentConfig\?\.remarks\s*\?:\s*service\.getString\(R\.string\.app_name\)\s*\)',
    )
    title_replacement = ".setContentTitle(BlueVpnPublicProfileName.forProfile(service, currentConfig))"
    patched_title = False
    for pattern in title_patterns:
        text, count = re.subn(pattern, title_replacement, text, count=1)
        if count:
            patched_title = True
            break
    if not patched_title and title_replacement not in text:
        raise RuntimeError(
            "Unsupported v2rayNG NotificationManager title contract: raw profile "
            "remarks could leak into Android system UI"
        )
    if re.search(r'\.setContentTitle\(\s*currentConfig\?\.remarks', text):
        raise RuntimeError("Raw v2rayNG profile remarks still leak into notification title")

    # BlueVPN keeps lightweight traffic stats visible in the persistent VPN notification.
    text = text.replace(
        "        if (MmkvManager.decodeSettingsBool(AppConfig.PREF_SPEED_ENABLED) != true) return\n",
        "",
    )
    text = text.replace(
        'val stopV2RayIntent = Intent(AppConfig.BROADCAST_ACTION_SERVICE)\n        stopV2RayIntent.`package` = AppConfig.ANG_PACKAGE\n        stopV2RayIntent.putExtra("key", AppConfig.MSG_STATE_STOP)',
        'val stopV2RayIntent = Intent(service, BlueVpnSystemActionReceiver::class.java)\n            .setAction(BlueVpnSystemController.ACTION_STOP)',
    )
    text = text.replace(
        'val restartV2RayIntent = Intent(AppConfig.BROADCAST_ACTION_SERVICE)\n        restartV2RayIntent.`package` = AppConfig.ANG_PACKAGE\n        restartV2RayIntent.putExtra("key", AppConfig.MSG_STATE_RESTART)',
        'val restartV2RayIntent = Intent(service, BlueVpnSystemActionReceiver::class.java)\n            .setAction(BlueVpnSystemController.ACTION_RESTART)',
    )
    path.write_text(text, encoding="utf-8")

def patch_app_config() -> None:
    path = APP / "src/main/java/com/v2ray/ang/AppConfig.kt"
    text = path.read_text(encoding="utf-8")
    website = CONFIG.get("website_url", "").strip()
    support = CONFIG.get("support_url", "").strip()
    if website:
        text = re.sub(r'const val APP_URL = ".*?"', f'const val APP_URL = "{website}"', text, count=1)
    if support:
        text = re.sub(r'const val TG_CHANNEL_URL = ".*?"', f'const val TG_CHANNEL_URL = "{support}"', text, count=1)
    path.write_text(text, encoding="utf-8")

def inject_bootstrap() -> None:
    source_dir = APP / "src/main/java/com/v2ray/ang/bluevpn"
    source_dir.mkdir(parents=True, exist_ok=True)
    (source_dir / "BlueVpnBootstrap.kt").write_bytes(base64.b64decode(BOOTSTRAP_B64))

    app_path = APP / "src/main/java/com/v2ray/ang/AngApplication.kt"
    text = app_path.read_text(encoding="utf-8")
    if "import com.v2ray.ang.bluevpn.BlueVpnBootstrap" not in text:
        text = text.replace(
            "import com.v2ray.ang.handler.SettingsManager",
            "import com.v2ray.ang.handler.SettingsManager\nimport com.v2ray.ang.bluevpn.BlueVpnBootstrap",
            1,
        )
    if "BlueVpnBootstrap.start(this)" not in text:
        text = text.replace(
            "SettingsManager.initApp(this)",
            "SettingsManager.initApp(this)\n        BlueVpnBootstrap.start(this)",
            1,
        )
    app_path.write_text(text, encoding="utf-8")

def inject_bluevpn_home() -> None:
    java_dir = APP / "src/main/java/com/v2ray/ang/ui"
    bluevpn_dir = APP / "src/main/java/com/v2ray/ang/bluevpn"
    drawable_dir = APP / "src/main/res/drawable"
    drawable_night_dir = APP / "src/main/res/drawable-night"
    values_dir = APP / "src/main/res/values"
    anim_dir = APP / "src/main/res/anim"
    xml_dir = APP / "src/main/res/xml"

    java_dir.mkdir(parents=True, exist_ok=True)
    bluevpn_dir.mkdir(parents=True, exist_ok=True)
    drawable_dir.mkdir(parents=True, exist_ok=True)
    drawable_night_dir.mkdir(parents=True, exist_ok=True)
    values_dir.mkdir(parents=True, exist_ok=True)
    anim_dir.mkdir(parents=True, exist_ok=True)
    xml_dir.mkdir(parents=True, exist_ok=True)

    files = {
        drawable_dir / "bluevpn_screen_background.xml": BLUEVPN_SCREEN_BACKGROUND_B64,
        drawable_night_dir / "bluevpn_screen_background.xml": BLUEVPN_SCREEN_BACKGROUND_NIGHT_B64,
        drawable_dir / "bluevpn_logo_background.xml": BLUEVPN_LOGO_BACKGROUND_B64,
        drawable_dir / "bluevpn_connect_ring.xml": BLUEVPN_CONNECT_RING_B64,
        drawable_dir / "bluevpn_status_dot.xml": BLUEVPN_STATUS_DOT_B64,
        drawable_dir / "bluevpn_icon_chip.xml": BLUEVPN_ICON_CHIP_B64,
        values_dir / "bluevpn_home_theme.xml": BLUEVPN_HOME_THEME_B64,
        values_dir / "bluevpn_ids.xml": BLUEVPN_IDS_B64,
        anim_dir / "bluevpn_fade_in.xml": BLUEVPN_FADE_IN_B64,
        anim_dir / "bluevpn_fade_out.xml": BLUEVPN_FADE_OUT_B64,
        xml_dir / "bluevpn_update_paths.xml": BLUEVPN_UPDATE_PATHS_B64,
    }

    for path, content_b64 in files.items():
        path.write_bytes(base64.b64decode(content_b64))

    # IDs are also canonical source. The older embedded snapshot still contains
    # retired AI view IDs, so always overwrite it with the reviewed public UI IDs.
    shutil.copy2(ROOT / "android-source/bluevpn_ids.xml", values_dir / "bluevpn_ids.xml")

    # Kotlin is single-source: reviewed files in android-source/ are copied
    # directly into the pinned upstream checkout. Only small XML assets without
    # canonical files remain embedded above for portable CI generation.
    plain_overrides = {
        java_dir / "BlueVpnHomeActivity.kt": ROOT / "android-source/BlueVpnHomeActivity.kt",
        bluevpn_dir / "BlueVpnAccountManager.kt": ROOT / "android-source/BlueVpnAccountManager.kt",
        bluevpn_dir / "BlueVpnStorePolicy.kt": ROOT / "android-source/BlueVpnStorePolicy.kt",
        bluevpn_dir / "BlueVpnAdsCarouselView.kt": ROOT / "android-source/BlueVpnAdsCarouselView.kt",
        bluevpn_dir / "BlueVpnAdActionRouter.kt": ROOT / "android-source/BlueVpnAdActionRouter.kt",
        bluevpn_dir / "BlueVpnFreeStoryAdGate.kt": ROOT / "android-source/BlueVpnFreeStoryAdGate.kt",
        bluevpn_dir / "BlueVpnUpdateManager.kt": ROOT / "android-source/BlueVpnUpdateManager.kt",
        java_dir / "BlueVpnUpdateInstallActivity.kt": ROOT / "android-source/BlueVpnUpdateInstallActivity.kt",
        bluevpn_dir / "BlueVpnUpdateFileProvider.kt": ROOT / "android-source/BlueVpnUpdateFileProvider.kt",
        bluevpn_dir / "BlueVpnLocationUtil.kt": ROOT / "android-source/BlueVpnLocationUtil.kt",
        bluevpn_dir / "BlueVpnPublicProfileName.kt": ROOT / "android-source/BlueVpnPublicProfileName.kt",
        bluevpn_dir / "BlueVpnExperience.kt": ROOT / "android-source/BlueVpnExperience.kt",
        bluevpn_dir / "BlueVpnTheme.kt": ROOT / "android-source/BlueVpnTheme.kt",
        bluevpn_dir / "BlueVpnAi.kt": ROOT / "android-source/BlueVpnAi.kt",
        bluevpn_dir / "BlueVpnIntelligenceCore.kt": ROOT / "android-source/BlueVpnIntelligenceCore.kt",
        bluevpn_dir / "BlueVpnNativeNetworkAdaptation.kt": ROOT / "android-source/BlueVpnNativeNetworkAdaptation.kt",
        bluevpn_dir / "BlueVpnNetworkRecoveryManager.kt": ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt",
        bluevpn_dir / "BlueVpnBackgroundReliability.kt": ROOT / "android-source/BlueVpnBackgroundReliability.kt",
        bluevpn_dir / "BlueVpnBackgroundOptimizer.kt": ROOT / "android-source/BlueVpnBackgroundOptimizer.kt",
        bluevpn_dir / "BlueVpnSupportNotifications.kt": ROOT / "android-source/BlueVpnSupportNotifications.kt",
        bluevpn_dir / "BlueVpnRuntimeAudit.kt": ROOT / "android-source/BlueVpnRuntimeAudit.kt",
        bluevpn_dir / "BlueVpnLiveReporter.kt": ROOT / "android-source/BlueVpnLiveReporter.kt",
        bluevpn_dir / "BlueVpnBootstrap.kt": ROOT / "android-source/BlueVpnBootstrap.kt",
        bluevpn_dir / "BlueVpnRuntimeGate.kt": ROOT / "android-source/BlueVpnRuntimeGate.kt",
        bluevpn_dir / "BlueVpnEntitlement.kt": ROOT / "android-source/BlueVpnEntitlement.kt",
        bluevpn_dir / "BlueVpnSmartSelector.kt": ROOT / "android-source/BlueVpnSmartSelector.kt",
        bluevpn_dir / "BlueVpnProfileManager.kt": ROOT / "android-source/BlueVpnProfileManager.kt",
        bluevpn_dir / "BlueVpnRouteIntelligence.kt": ROOT / "android-source/BlueVpnRouteIntelligence.kt",
        bluevpn_dir / "BlueVpnSubscriptionIntelligence.kt": ROOT / "android-source/BlueVpnSubscriptionIntelligence.kt",
        bluevpn_dir / "BlueVpnIrcfIntelligence.kt": ROOT / "android-source/BlueVpnIrcfIntelligence.kt",
        bluevpn_dir / "BlueVpnPoolOrchestrator.kt": ROOT / "android-source/BlueVpnPoolOrchestrator.kt",
        bluevpn_dir / "BlueVpnWarpEngine.kt": ROOT / "android-source/BlueVpnWarpEngine.kt",
        bluevpn_dir / "BlueVpnWarpKeepAliveService.kt": ROOT / "android-source/BlueVpnWarpKeepAliveService.kt",
        bluevpn_dir / "BlueVpnSystemController.kt": ROOT / "android-source/BlueVpnSystemController.kt",
        bluevpn_dir / "BlueVpnQuickTileService.kt": ROOT / "android-source/BlueVpnQuickTileService.kt",
        bluevpn_dir / "BlueVpnSystemActionReceiver.kt": ROOT / "android-source/BlueVpnSystemActionReceiver.kt",
        bluevpn_dir / "BlueVpnWarpPolicy.kt": ROOT / "android-source/BlueVpnWarpPolicy.kt",
        java_dir / "BlueVpnServersActivity.kt": ROOT / "android-source/BlueVpnServersActivity.kt",
        java_dir / "BlueVpnSubscriptionsActivity.kt": ROOT / "android-source/BlueVpnSubscriptionsActivity.kt",
        bluevpn_dir / "BlueVpnSmsOtpAutoFill.kt": ROOT / "android-source/BlueVpnSmsOtpAutoFill.kt",
        java_dir / "BlueVpnSettingsActivity.kt": ROOT / "android-source/BlueVpnSettingsActivity.kt",
        java_dir / "BlueVpnSupportActivity.kt": ROOT / "android-source/BlueVpnSupportActivity.kt",
    }
    for target, source in plain_overrides.items():
        if not source.exists():
            raise RuntimeError(f"Canonical BlueVPN source is missing: {source}")
        shutil.copy2(source, target)

    # Keep third-party advertising code completely outside the Google Play
    # variant. The F-Droid/direct flavor gets the real SDK-backed manager; the
    # Play flavor gets a no-SDK stub with the same public surface.
    fdroid_bluevpn_dir = APP / "src" / "fdroid" / "java" / "com" / "v2ray" / "ang" / "bluevpn"
    play_bluevpn_dir = APP / "src" / "playstore" / "java" / "com" / "v2ray" / "ang" / "bluevpn"
    fdroid_bluevpn_dir.mkdir(parents=True, exist_ok=True)
    play_bluevpn_dir.mkdir(parents=True, exist_ok=True)
    shutil.copy2(
        ROOT / "android-source" / "BlueVpnTapsellManager.kt",
        fdroid_bluevpn_dir / "BlueVpnTapsellManager.kt",
    )
    shutil.copy2(
        ROOT / "android-source" / "BlueVpnTapsellManagerPlay.kt",
        play_bluevpn_dir / "BlueVpnTapsellManager.kt",
    )

    home_runtime = (java_dir / "BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    if "CoreServiceManager.startVService(this, guid)" not in home_runtime:
        raise RuntimeError("BlueVPN Home is not mounted directly on v2rayNG CoreServiceManager")
    if "CoreServiceManager.stopVService" not in home_runtime:
        raise RuntimeError("BlueVPN Home is not using v2rayNG stop lifecycle")
    if "BlueVpnEngineManager" in home_runtime or "BlueVpnSingBox" in home_runtime:
        raise RuntimeError("Legacy dual-engine runtime still leaked into BlueVPN Home")
    if "BlueVpnIrcfIntelligence.adaptiveProbeUrls" in home_runtime:
        if "import com.v2ray.ang.bluevpn.BlueVpnIrcfIntelligence" not in home_runtime:
            raise RuntimeError(
                "BlueVpnHomeActivity references BlueVpnIrcfIntelligence without the explicit import"
            )
        if not (bluevpn_dir / "BlueVpnIrcfIntelligence.kt").is_file():
            raise RuntimeError(
                "BlueVpnIrcfIntelligence source was not copied into the generated Android project"
            )
        if ".forEach(::add)" in home_runtime:
            raise RuntimeError(
                "Adaptive probe insertion must use an explicit lambda to avoid Kotlin overload ambiguity"
            )

    home_source = (java_dir / "BlueVpnHomeActivity.kt").read_text(
        encoding="utf-8",
    )
    if "completion.submit<" in home_source:
        raise RuntimeError(
            "Invalid Kotlin generated source: ExecutorCompletionService.submit "
            "must not receive explicit type arguments"
        )
    if "completion.submit {" not in home_source:
        raise RuntimeError(
            "Fast-connect completion task was not generated correctly"
        )

    tapsell_source = (fdroid_bluevpn_dir / "BlueVpnTapsellManager.kt").read_text(
        encoding="utf-8",
    )
    if "ir.tapsell.plus" in tapsell_source or "TapsellPlus" in tapsell_source:
        raise RuntimeError("Deprecated Tapsell Plus runtime leaked into Android overlay")
    if "import ir.tapsell.mediation.Tapsell" not in tapsell_source:
        raise RuntimeError("Tapsell Mediation native SDK import is missing from direct flavor")
    if "BuildConfig.BLUEVPN_TAPSELL_APP_ID" not in tapsell_source:
        raise RuntimeError("Tapsell direct-flavor App ID guard is missing")

    play_tapsell = (play_bluevpn_dir / "BlueVpnTapsellManager.kt").read_text(encoding="utf-8")
    if "import ir.tapsell" in play_tapsell or "ir.tapsell." in play_tapsell:
        raise RuntimeError("Google Play Tapsell stub must not import/reference the third-party SDK")
    if "onUnavailable?.invoke()" not in play_tapsell:
        raise RuntimeError("Google Play Tapsell stub must fail closed without affecting VPN state")

    ads_source = (bluevpn_dir / "BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    if 'optJSONObject("advertising")' not in ads_source or "slideRunnable" not in ads_source:
        raise RuntimeError("Advertising carousel source was not generated correctly")
    if 'scheme == "http" || scheme == "https"' not in ads_source:
        raise RuntimeError("Advertising links must be restricted to HTTP(S)")
    if "private fun revealBitmap" not in ads_source or "private fun hideBanner" not in ads_source:
        raise RuntimeError("Advertising view must remain hidden until an image is decoded")
    if "params.height = targetHeight" not in ads_source or "MeasureSpec.makeMeasureSpec(0" not in ads_source:
        raise RuntimeError("Advertising view must use a fixed compact height and zero-height hidden state")

    servers_source = (java_dir / "BlueVpnServersActivity.kt").read_text(
        encoding="utf-8",
    )
    if re.search(r"(?m)^\s*singleLine\s*=", servers_source):
        raise RuntimeError(
            "Invalid Kotlin generated source: use EditText.isSingleLine instead "
            "of the unresolved singleLine synthetic property"
        )
    if "isSingleLine = true" not in servers_source:
        raise RuntimeError(
            "Server search field single-line configuration was not generated"
        )

def generate_icons() -> None:
    source = Image.open(ROOT / "branding/icon.png").convert("RGBA")
    sizes = {"mipmap-mdpi":48, "mipmap-hdpi":72, "mipmap-xhdpi":96, "mipmap-xxhdpi":144, "mipmap-xxxhdpi":192}
    res = APP / "src/main/res"
    for folder, size in sizes.items():
        target = res / folder
        target.mkdir(parents=True, exist_ok=True)
        square = source.resize((size, size), Image.Resampling.LANCZOS)
        square.save(target / "ic_launcher.png")
        square.save(target / "ic_launcher_round.png")
        source.resize((size * 2, size), Image.Resampling.LANCZOS).save(target / "ic_banner.png")
    adaptive_dir = res / "mipmap-anydpi-v26"
    if adaptive_dir.exists():
        for path in adaptive_dir.glob("ic_launcher*.xml"):
            path.unlink()
    source.resize((512, 512), Image.Resampling.LANCZOS).save(APP / "src/main/ic_launcher-web.png")

def add_source_notice() -> None:
    assets = APP / "src/main/assets"
    assets.mkdir(parents=True, exist_ok=True)
    (assets / "BLUEVPN_SOURCE.txt").write_text(
        "BlueVPN Android is a branded/custom UI distribution built directly on the official v2rayNG 2.2.6 Android source under GNU GPL v3.\n"
        "The runtime path (import, config generation, CoreServiceManager, CoreVpnService, TUN and Xray startup) remains v2rayNG-owned.\n"
        "AndroidLibXrayLite is resolved from the exact v2rayNG 2.2.6 submodule (currently v26.7.5); v2rayNG 2.2.6 release notes label its Xray-core as v26.6.27. These are separate version namespaces.\n"
        "BlueVPN adds its own account, location, entitlement, updater and UI layers. Premium remains on the stock v2rayNG/Xray runtime; Free can use the separately packaged Aether WARP process through a loopback SOCKS bridge.\n"
        "Aether source pin: https://github.com/CluvexStudio/Aether/tree/a26159b82a70048b459e0128213c71767abecb8a (AGPL-3.0). No Oblivion application code is copied into BlueVPN.\n"
        "Upstream source: https://github.com/2dust/v2rayNG\n",
        encoding="utf-8",
    )



def main() -> None:
    if not APP.exists():
        raise RuntimeError("Upstream project not found at upstream/V2rayNG")
    runtime_snapshot = snapshot_upstream_runtime()
    patch_tapsell_repository()
    patch_build_gradle()
    patch_strings()
    patch_manifest()
    write_distribution_manifest_overlays()
    patch_system_notification()
    patch_app_config()
    inject_bootstrap()
    inject_bluevpn_home()
    assert_upstream_runtime_unchanged(runtime_snapshot)
    generate_icons()
    add_source_notice()
    print("BlueVPN branding applied successfully.")
    print(f"Package: {CONFIG['application_id']}")
    print(f"Version: {CONFIG['version_name']} ({CONFIG['version_code']})")

if __name__ == "__main__":
    main()
