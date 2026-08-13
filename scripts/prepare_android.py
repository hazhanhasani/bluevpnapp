from __future__ import annotations

import base64
import json
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


def patch_build_gradle() -> None:
    path = APP / "build.gradle.kts"
    text = path.read_text(encoding="utf-8")
    text = re.sub(r'applicationId\s*=\s*"[^"]+"', f'applicationId = "{CONFIG["application_id"]}"', text, count=1)
    text = re.sub(r'versionCode\s*=\s*\d+', f'versionCode = {int(CONFIG["version_code"])}', text, count=1)
    text = re.sub(r'versionName\s*=\s*"[^"]+"', f'versionName = "{CONFIG["version_name"]}"', text, count=1)
    text = text.replace('v2rayNG_${variant.versionName}', 'BlueVPN_${variant.versionName}')
    api_value = CONFIG.get("api_base_url", "").rstrip("/")
    marker = f'applicationId = "{CONFIG["application_id"]}"'
    field = '\n        buildConfigField("String", "BLUEVPN_API_BASE_URL", "\\"' + api_value + '\\"")'
    if "BLUEVPN_API_BASE_URL" not in text:
        text = text.replace(marker, marker + field, 1)
    dependencies_marker = "dependencies {"
    if dependencies_marker not in text:
        raise RuntimeError("Gradle dependencies block not found")

    # Tapsell Plus adds a sizeable dependency graph. In the generated v2rayNG 2.2.6
    # app this can leave WorkManager's RemoteWorkManager API visible while the
    # com.google.common.util.concurrent.ListenableFuture class is absent from the
    # Kotlin compile classpath. RemoteWorkManager exposes ListenableFuture directly
    # in cancel/enqueue signatures, so keep the Android Guava artifact explicit.
    # Using full Guava is intentional: forcing listenablefuture:1.0 can conflict with
    # Guava's 9999.0 empty compatibility artifact when another SDK brings Guava.
    required_dependencies = (
        'implementation("com.google.guava:guava:33.6.0-android")',
        'implementation("ir.tapsell.plus:tapsell-plus-sdk-android:2.3.3")',
    )
    for dependency in required_dependencies:
        if dependency not in text:
            text = text.replace(
                dependencies_marker,
                dependencies_marker + "\n    " + dependency,
                1,
            )
    path.write_text(text, encoding="utf-8")

    # The integration uses a defensive reflection boundary so vendor SDK
    # failures cannot crash VPN runtime. Keep Tapsell entry points under R8.
    rules_path = APP / "proguard-rules.pro"
    rules = rules_path.read_text(encoding="utf-8") if rules_path.exists() else ""
    tapsell_rules = "\n# BlueVPN Tapsell Plus integration\n-keep class ir.tapsell.** { *; }\n-dontwarn ir.tapsell.**\n"
    if "-keep class ir.tapsell.**" not in rules:
        rules_path.write_text(rules.rstrip() + tapsell_rules, encoding="utf-8")


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
        '            <meta-data\n'
        '                android:name="android.app.shortcuts"\n'
        '                android:resource="@xml/shortcuts" />\n'
        '        </activity>\n\n'
        '        <activity\n'
        '            android:name=".ui.MainActivity"\n'
        '            android:exported="true"\n'
        '            android:launchMode="singleTask">\n'
        '            <intent-filter>\n'
        '                <action android:name="android.service.quicksettings.action.QS_TILE_PREFERENCES" />\n'
        '            </intent-filter>\n'
        '        </activity>\n\n'
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
        '            android:theme="@style/BlueVpnHomeTheme" />'
    )

    if 'android:name=".ui.BlueVpnHomeActivity"' not in text:
        text, count = launcher_pattern.subn(launcher_replacement, text, count=1)
        if count != 1:
            raise RuntimeError("Could not replace the MainActivity launcher block")

    text = _ensure_manifest_permission(
        text,
        "android.permission.REQUEST_INSTALL_PACKAGES",
    )
    text = _ensure_manifest_permission(
        text,
        "android.permission.CHANGE_NETWORK_STATE",
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

    url_scheme_path = APP / "src/main/java/com/v2ray/ang/ui/UrlSchemeActivity.kt"
    url_scheme_text = url_scheme_path.read_text(encoding="utf-8")
    url_scheme_text = url_scheme_text.replace(
        "startActivity(Intent(this, MainActivity::class.java))",
        "startActivity(Intent(this, BlueVpnHomeActivity::class.java))",
    )
    url_scheme_path.write_text(url_scheme_text, encoding="utf-8")

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

def patch_legacy_tls_profiles() -> None:
    """Keep upstream v2rayNG TLS/profile parsing unchanged.

    Earlier BlueVPN builds injected extra allowInsecure filters into
    AngConfigManager. That could silently remove subscription profiles before
    the upstream parser/core had a chance to normalize or validate them.
    Runtime tunnel verification now owns invalid-route quarantine instead.
    """
    return

def patch_shadowsocks_transport_queries() -> None:
    """Backport SIP002 transport-query preservation to pinned v2rayNG 2.2.6.

    The 2.2.6 Shadowsocks parser reads the URI query for plugin handling but
    does not feed the normal transport/TLS query fields into the shared profile
    mapper. That can turn an otherwise valid ss://?type=ws|grpc|xhttp... link
    into a plain TCP profile in BlueVPN. Keep the upstream parser and apply the
    narrow compatibility fix at build preparation time.
    """
    path = APP / "src/main/java/com/v2ray/ang/fmt/ShadowsocksFmt.kt"
    if not path.exists():
        raise RuntimeError("Pinned ShadowsocksFmt.kt was not found")
    text = path.read_text(encoding="utf-8")

    marker = "getItemFormQuery(config, queryParam)"
    if marker not in text:
        pattern = r"(?P<indent>^[ \t]*)val queryParam = getQueryParam\(uri\)\s*$"
        match = re.search(pattern, text, flags=re.MULTILINE)
        if not match:
            raise RuntimeError("Could not locate SIP002 query parsing in ShadowsocksFmt.kt")
        insertion = match.group(0) + "\n" + match.group("indent") + marker
        text = text[:match.start()] + insertion + text[match.end():]

    legacy_export = "return toUri(config, Utils.encode(pw, true), null)"
    if legacy_export in text:
        text = text.replace(
            legacy_export,
            "return toUri(config, Utils.encode(pw, true), getQueryDic(config))",
            1,
        )
    elif "getQueryDic(config)" not in text:
        raise RuntimeError("Could not preserve Shadowsocks transport query on export")

    path.write_text(text, encoding="utf-8")

def _replace_kotlin_function(
    text: str,
    signature_pattern: str,
    replacement: str,
    label: str,
    already_marker: str | None = None,
) -> str:
    """Replace one Kotlin function without depending on its exact upstream body.

    GitHub Actions checks out the pinned upstream source fresh for every build.
    Exact multi-line string replacements are brittle because harmless upstream
    whitespace/comment changes make the prepare step fail before Gradle.  This
    helper locates the function signature and then finds the matching closing
    brace while ignoring braces inside Kotlin strings and comments.
    """
    if already_marker and already_marker in text:
        return text

    match = re.search(signature_pattern, text, flags=re.MULTILINE)
    if not match:
        nearby = "\n".join(
            line for line in text.splitlines()
            if any(token in line for token in ("startVService", "stopCoreLoop", "onStartCommand", "setupVpnService", "stopAllService"))
        )[:2400]
        raise RuntimeError(
            f"Could not patch v2rayNG runtime: {label}; signature not found. "
            f"Nearby runtime declarations:\n{nearby}"
        )

    brace_start = text.find("{", match.start(), match.end())
    if brace_start < 0:
        raise RuntimeError(f"Could not patch v2rayNG runtime: {label}; opening brace not found")

    depth = 0
    i = brace_start
    state = "code"
    block_comment_depth = 0
    while i < len(text):
        if state == "code":
            if text.startswith("//", i):
                state = "line_comment"
                i += 2
                continue
            if text.startswith("/*", i):
                state = "block_comment"
                block_comment_depth = 1
                i += 2
                continue
            if text.startswith('"""', i):
                state = "triple_string"
                i += 3
                continue
            ch = text[i]
            if ch == '"':
                state = "string"
                i += 1
                continue
            if ch == "'":
                state = "char"
                i += 1
                continue
            if ch == "{":
                depth += 1
            elif ch == "}":
                depth -= 1
                if depth == 0:
                    end = i + 1
                    # Preserve the upstream newline after the function so the
                    # replacement stays stable regardless of CRLF/LF checkout.
                    if end < len(text) and text[end] == "\r":
                        end += 1
                    if end < len(text) and text[end] == "\n":
                        end += 1
                    return text[:match.start()] + replacement.rstrip("\n") + "\n" + text[end:]
            i += 1
            continue

        if state == "line_comment":
            if text[i] == "\n":
                state = "code"
            i += 1
            continue

        if state == "block_comment":
            if text.startswith("/*", i):
                block_comment_depth += 1
                i += 2
                continue
            if text.startswith("*/", i):
                block_comment_depth -= 1
                i += 2
                if block_comment_depth == 0:
                    state = "code"
                continue
            i += 1
            continue

        if state == "triple_string":
            if text.startswith('"""', i):
                state = "code"
                i += 3
            else:
                i += 1
            continue

        if state in {"string", "char"}:
            ch = text[i]
            if ch == "\\":
                i += 2
                continue
            if (state == "string" and ch == '"') or (state == "char" and ch == "'"):
                state = "code"
            i += 1
            continue

    raise RuntimeError(f"Could not patch v2rayNG runtime: {label}; closing brace not found")


def patch_v2rayng_runtime_lifecycle() -> None:
    """Patch pinned v2rayNG 2.2.6 for exact-candidate lifecycle semantics.

    BlueVPN keeps upstream parsers/config generation intact, but the 2.2.6
    service lifecycle reports STOP_SUCCESS before the asynchronous core stop has
    actually completed and RUNNING/START_SUCCESS do not identify which GUID is
    running.  That makes fast failover vulnerable to stale-core/port races.
    This narrow patch gives BlueVPN an exact GUID handshake and makes stop
    completion authoritative before the next candidate is started.
    """

    def replace_exact(text: str, old: str, new: str, label: str) -> str:
        if new in text:
            return text
        if old not in text:
            raise RuntimeError(f"Could not patch v2rayNG runtime: {label}")
        return text.replace(old, new, 1)

    core_path = APP / "src/main/java/com/v2ray/ang/core/CoreServiceManager.kt"
    vpn_path = APP / "src/main/java/com/v2ray/ang/service/CoreVpnService.kt"
    vm_path = APP / "src/main/java/com/v2ray/ang/viewmodel/MainViewModel.kt"
    for runtime_path in (core_path, vpn_path, vm_path):
        if not runtime_path.exists():
            raise RuntimeError(f"Pinned v2rayNG runtime file missing: {runtime_path}")

    core = core_path.read_text(encoding="utf-8")
    core = replace_exact(
        core,
        "    private var currentConfig: ProfileItem? = null\n",
        "    private var currentConfig: ProfileItem? = null\n"
        "    @Volatile private var currentGuid: String? = null\n"
        "    @Volatile private var stopInProgress = false\n",
        "CoreServiceManager runtime identity fields",
    )

    old_start = '''    fun startVService(context: Context, guid: String? = null) {
        LogUtil.i(AppConfig.TAG, "StartCore-Manager: startVService from ${context::class.java.simpleName}")

        if (guid != null) {
            MmkvManager.setSelectServer(guid)
        }
        try {
            startContextService(context)
        } catch (e: Exception) {
            LogUtil.e(AppConfig.TAG, "StartCore-Manager: ${e.message}", e)
            context.toast(e.message ?: e.javaClass.simpleName)
        }
    }
'''
    new_start = '''    fun startVService(context: Context, guid: String? = null) {
        startVServiceExact(context, guid)
    }

    /**
     * BlueVPN exact-candidate start handshake.
     *
     * The requested GUID is validated and copied into the service Intent so a
     * subscription refresh cannot silently redirect the start to a different
     * MMKV selection between Activity selection and CoreVpnService startup.
     */
    fun startVServiceExact(context: Context, guid: String? = null): Boolean {
        LogUtil.i(AppConfig.TAG, "StartCore-Manager: startVServiceExact from ${context::class.java.simpleName}")
        val requestedGuid = guid.orEmpty().trim().ifBlank {
            MmkvManager.getSelectServer().orEmpty().trim()
        }
        if (requestedGuid.isBlank()) {
            LogUtil.e(AppConfig.TAG, "StartCore-Manager: No exact server selected")
            context.toast(R.string.app_tile_first_use)
            return false
        }
        if (coreController.isRunning) {
            val sameRoute = currentGuid == requestedGuid
            LogUtil.w(
                AppConfig.TAG,
                "StartCore-Manager: Core already running guid=$currentGuid requested=$requestedGuid"
            )
            return sameRoute
        }
        MmkvManager.setSelectServer(requestedGuid)
        return try {
            startContextService(context, requestedGuid)
            true
        } catch (e: Exception) {
            LogUtil.e(AppConfig.TAG, "StartCore-Manager: ${e.message}", e)
            context.toast(e.message ?: e.javaClass.simpleName)
            false
        }
    }
'''
    core = _replace_kotlin_function(
        core,
        r"^[ \t]*fun[ \t]+startVService[ \t]*\([ \t]*context[ \t]*:[ \t]*Context[ \t]*,[ \t]*guid[ \t]*:[ \t]*String\?[ \t]*=[ \t]*null[ \t]*\)[ \t]*\{",
        new_start,
        "exact start entry point",
        already_marker="fun startVServiceExact(",
    )
    core = replace_exact(
        core,
        "    fun getRunningServerName() = currentConfig?.remarks.orEmpty()\n",
        "    fun getRunningServerName() = currentConfig?.remarks.orEmpty()\n"
        "    fun getRunningServerGuid() = currentGuid.orEmpty()\n",
        "running GUID accessor",
    )

    core = replace_exact(
        core,
        "    private fun startContextService(context: Context) {\n",
        "    private fun startContextService(context: Context, requestedGuid: String? = null) {\n",
        "startContextService exact GUID parameter",
    )
    core = replace_exact(
        core,
        '''        val guid = MmkvManager.getSelectServer()
            ?: run {
''',
        '''        val guid = requestedGuid?.trim()?.takeIf { it.isNotBlank() }
            ?: MmkvManager.getSelectServer()
            ?: run {
''',
        "startContextService exact GUID resolution",
    )
    core = replace_exact(
        core,
        '''        try {
            ContextCompat.startForegroundService(context, intent)
''',
        '''        intent.putExtra("bluevpn_target_guid", guid)
        try {
            ContextCompat.startForegroundService(context, intent)
''',
        "service Intent exact GUID",
    )
    core = replace_exact(
        core,
        "    fun startCoreLoop(vpnInterface: ParcelFileDescriptor?): Boolean {\n",
        "    fun startCoreLoop(vpnInterface: ParcelFileDescriptor?, requestedGuid: String? = null): Boolean {\n",
        "startCoreLoop exact GUID parameter",
    )
    core = replace_exact(
        core,
        "            doStartCoreLoop(service, vpnInterface)\n",
        "            doStartCoreLoop(service, vpnInterface, requestedGuid)\n",
        "startCoreLoop exact GUID forwarding",
    )
    core = replace_exact(
        core,
        "    private fun doStartCoreLoop(service: Service, vpnInterface: ParcelFileDescriptor?) {\n",
        "    private fun doStartCoreLoop(service: Service, vpnInterface: ParcelFileDescriptor?, requestedGuid: String? = null) {\n",
        "doStartCoreLoop exact GUID parameter",
    )
    core = replace_exact(
        core,
        '        val guid = MmkvManager.getSelectServer() ?: error("No server selected")\n',
        '        val guid = requestedGuid?.trim()?.takeIf { it.isNotBlank() }\n'
        '            ?: MmkvManager.getSelectServer()\n'
        '            ?: error("No server selected")\n',
        "doStartCoreLoop exact GUID resolution",
    )
    core = replace_exact(
        core,
        '''        if (!coreController.isRunning) {
            error("Core failed to start")
        }
''',
        '''        if (!coreController.isRunning) {
            error("Core failed to start")
        }
        currentGuid = guid
''',
        "running GUID capture",
    )
    core = replace_exact(
        core,
        '        MessageUtil.sendMsg2UI(service, AppConfig.MSG_STATE_START_SUCCESS, "")\n',
        '        MessageUtil.sendMsg2UI(service, AppConfig.MSG_STATE_START_SUCCESS, guid)\n',
        "START_SUCCESS exact GUID",
    )

    old_stop = '''    fun stopCoreLoop(): Boolean {
        val service = getService() ?: return false
        if (coreController.isRunning) {
            CoroutineScope(Dispatchers.IO).launch {
                try {
                    coreController.stopLoop()
                } catch (e: Exception) {
                    LogUtil.e(AppConfig.TAG, "StartCore-Manager: Failed to stop V2Ray loop", e)
                }
            }
        }
        // Close existing browser dialer
        CoreNativeManager.reconcileBrowserDialer("")
        if (browserDialer != null) {
            browserDialer!!.stop()
            browserDialer = null
        }

        MessageUtil.sendMsg2UI(service, AppConfig.MSG_STATE_STOP_SUCCESS, "")
        NotificationManager.cancelNotification()
        try {
            service.unregisterReceiver(mMsgReceive)
        } catch (e: Exception) {
            LogUtil.e(AppConfig.TAG, "StartCore-Manager: Failed to unregister receiver", e)
        }

        return true
    }
'''
    new_stop = '''    @Synchronized
    fun stopCoreLoop(): Boolean {
        val service = getService() ?: return false
        if (stopInProgress) {
            LogUtil.w(AppConfig.TAG, "StartCore-Manager: Stop already in progress")
            return !coreController.isRunning
        }
        stopInProgress = true
        var stopped = !coreController.isRunning
        try {
            if (!stopped) {
                try {
                    // Deliberately synchronous: STOP_SUCCESS must mean the old
                    // Xray loop has released its listeners/ports, not merely that
                    // a background stop coroutine was scheduled.
                    coreController.stopLoop()
                } catch (e: Exception) {
                    LogUtil.e(AppConfig.TAG, "StartCore-Manager: Failed to stop V2Ray loop", e)
                }
                val deadline = android.os.SystemClock.elapsedRealtime() + 1_800L
                while (coreController.isRunning && android.os.SystemClock.elapsedRealtime() < deadline) {
                    try {
                        Thread.sleep(25L)
                    } catch (_: InterruptedException) {
                        Thread.currentThread().interrupt()
                        break
                    }
                }
                stopped = !coreController.isRunning
            }

            // Close existing browser dialer only after the core stop request has
            // completed so a new candidate cannot inherit stale runtime state.
            CoreNativeManager.reconcileBrowserDialer("")
            if (browserDialer != null) {
                browserDialer!!.stop()
                browserDialer = null
            }
            NotificationManager.cancelNotification()
            try {
                service.unregisterReceiver(mMsgReceive)
            } catch (e: Exception) {
                LogUtil.e(AppConfig.TAG, "StartCore-Manager: Failed to unregister receiver", e)
            }

            if (stopped) {
                currentConfig = null
                currentGuid = null
                MessageUtil.sendMsg2UI(service, AppConfig.MSG_STATE_STOP_SUCCESS, "")
            } else {
                MessageUtil.sendMsg2UI(service, AppConfig.MSG_STATE_START_FAILURE, "Core stop timeout")
            }
            return stopped
        } finally {
            stopInProgress = false
        }
    }
'''
    core = _replace_kotlin_function(
        core,
        r"^[ \t]*fun[ \t]+stopCoreLoop[ \t]*\([ \t]*\)[ \t]*:[ \t]*Boolean[ \t]*\{",
        new_stop,
        "authoritative synchronous core stop",
        already_marker="@Synchronized\n    fun stopCoreLoop(): Boolean",
    )
    core = replace_exact(
        core,
        '''        override fun shutdown(): Long {
            val serviceControl = serviceControl?.get() ?: return -1
''',
        '''        override fun shutdown(): Long {
            if (stopInProgress) return 0
            val serviceControl = serviceControl?.get() ?: return -1
''',
        "shutdown recursion guard",
    )
    core = replace_exact(
        core,
        '                        MessageUtil.sendMsg2UI(serviceControl.getService(), AppConfig.MSG_STATE_RUNNING, "")\n',
        '                        MessageUtil.sendMsg2UI(serviceControl.getService(), AppConfig.MSG_STATE_RUNNING, currentGuid.orEmpty())\n',
        "RUNNING exact GUID",
    )
    core_path.write_text(core, encoding="utf-8")

    vpn = vpn_path.read_text(encoding="utf-8")
    vpn = replace_exact(
        vpn,
        "    private var isRunning = false\n",
        "    private var isRunning = false\n"
        "    @Volatile private var blueVpnTargetGuid: String? = null\n",
        "CoreVpnService exact target field",
    )
    if "import com.v2ray.ang.util.MessageUtil" not in vpn:
        vpn = replace_exact(
            vpn,
            "import com.v2ray.ang.util.LogUtil\n",
            "import com.v2ray.ang.util.LogUtil\nimport com.v2ray.ang.util.MessageUtil\n",
            "CoreVpnService MessageUtil import",
        )

    new_vpn_start = '''    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        LogUtil.i(AppConfig.TAG, "StartCore-VPN: Service command received")
        val requestedGuid = intent?.getStringExtra("bluevpn_target_guid")
            ?.trim()
            ?.takeIf { it.isNotBlank() }
            ?: MmkvManager.getSelectServer()?.trim()?.takeIf { it.isNotBlank() }

        // CoreVpnService lives in :RunSoLibV2RayDaemon. Only this process can
        // authoritatively know whether an old Xray loop is still alive. Never
        // rebuild TUN or overwrite MMKV selection while that loop is running;
        // report its exact GUID so the UI stops it before retrying.
        if (CoreServiceManager.isRunning()) {
            val runningGuid = CoreServiceManager.getRunningServerGuid()
            LogUtil.w(
                AppConfig.TAG,
                "StartCore-VPN: Reject overlapping start running=$runningGuid requested=$requestedGuid"
            )
            MessageUtil.sendMsg2UI(this, AppConfig.MSG_STATE_RUNNING, runningGuid)
            return START_STICKY
        }

        requestedGuid?.let { MmkvManager.setSelectServer(it) }
        blueVpnTargetGuid = requestedGuid
        NotificationManager.showNotification(null)
        if (setupVpnService()) {
            startService()
        }
        return START_STICKY
        //return super.onStartCommand(intent, flags, startId)
    }
'''
    vpn = _replace_kotlin_function(
        vpn,
        r"^[ \t]*override[ \t]+fun[ \t]+onStartCommand[ \t]*\([^{\n]*\)[ \t]*:[ \t]*Int[ \t]*\{",
        new_vpn_start,
        "CoreVpnService exact intent/start gating",
        already_marker='getStringExtra("bluevpn_target_guid")',
    )
    old_missing_interface = '''        if (!::mInterface.isInitialized) {
            LogUtil.e(AppConfig.TAG, "StartCore-VPN: Interface not initialized")
            return
        }
'''
    if old_missing_interface in vpn:
        vpn = vpn.replace(
            old_missing_interface,
            '''        if (!::mInterface.isInitialized) {
            LogUtil.e(AppConfig.TAG, "StartCore-VPN: Interface not initialized")
            MessageUtil.sendMsg2UI(this, AppConfig.MSG_STATE_START_FAILURE, "VPN interface not initialized")
            stopSelf()
            return
        }
''',
            1,
        )
    elif 'AppConfig.MSG_STATE_START_FAILURE, "VPN interface not initialized"' not in vpn:
        raise RuntimeError("Could not patch v2rayNG runtime: CoreVpnService missing-interface failure")
    new_setup_vpn = '''    private fun setupVpnService(): Boolean {
        val prepare = prepare(this)
        if (prepare != null) {
            LogUtil.e(AppConfig.TAG, "StartCore-VPN: Permission not granted")
            MessageUtil.sendMsg2UI(this, AppConfig.MSG_STATE_START_FAILURE, "VPN permission not granted")
            stopSelf()
            return false
        }
        if (configureVpnService() != true) {
            LogUtil.e(AppConfig.TAG, "StartCore-VPN: Configuration failed")
            MessageUtil.sendMsg2UI(this, AppConfig.MSG_STATE_START_FAILURE, "VPN configuration failed")
            stopSelf()
            return false
        }

        runTun2socks()
        return true
    }
'''
    vpn = _replace_kotlin_function(
        vpn,
        r"^[ \t]*private[ \t]+fun[ \t]+setupVpnService[ \t]*\([ \t]*\)[ \t]*\{",
        new_setup_vpn,
        "CoreVpnService setup result",
        already_marker="private fun setupVpnService(): Boolean",
    )

    new_vpn_start_service = '''    override fun startService() {
        if (!::mInterface.isInitialized) {
            LogUtil.e(AppConfig.TAG, "StartCore-VPN: Interface not initialized")
            MessageUtil.sendMsg2UI(this, AppConfig.MSG_STATE_START_FAILURE, "VPN interface not initialized")
            blueVpnTargetGuid = null
            stopSelf()
            return
        }
        val requestedGuid = blueVpnTargetGuid
        if (!CoreServiceManager.startCoreLoop(mInterface, requestedGuid)) {
            LogUtil.e(AppConfig.TAG, "StartCore-VPN: Failed to start exact core loop guid=$requestedGuid")
            blueVpnTargetGuid = null
            stopAllService()
            return
        }
        blueVpnTargetGuid = null

        // Start LAN sharing if enabled in settings
        RootLanSharing.startClientSharing(this)
    }
'''
    vpn = _replace_kotlin_function(
        vpn,
        r"^[ \t]*override[ \t]+fun[ \t]+startService[ \t]*\([ \t]*\)[ \t]*\{",
        new_vpn_start_service,
        "CoreVpnService direct exact GUID core start",
        already_marker="CoreServiceManager.startCoreLoop(mInterface, requestedGuid)",
    )

    old_vpn_stop = '''    private fun stopAllService(isForced: Boolean = true) {
//        val configName = defaultDPreference.getPrefString(PREF_CURR_CONFIG_GUID, "")
//        val emptyInfo = VpnNetworkInfo()
//        val info = loadVpnNetworkInfo(configName, emptyInfo)!! + (lastNetworkInfo ?: emptyInfo)
//        saveVpnNetworkInfo(configName, info)
        isRunning = false
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            try {
                connectivity.unregisterNetworkCallback(defaultNetworkCallback)
            } catch (e: Exception) {
                LogUtil.w(AppConfig.TAG, "StartCore-VPN: Failed to unregister callback", e)
            }
        }
        tun2SocksService?.stopTun2Socks()
        tun2SocksService = null

        RootLanSharing.stopClientSharing(this)

        CoreServiceManager.stopCoreLoop()
        if (isForced) {
            //stopSelf has to be called ahead of mInterface.close(). otherwise v2ray core cannot be stooped
            //It's strage but true.
            //This can be verified by putting stopself() behind and call stopLoop and startLoop
            //in a row for several times. You will find that later created v2ray core report port in use
            //which means the first v2ray core somehow failed to stop and release the port.
            stopSelf()
            // Add a small delay to allow the async core stop operation to complete
            // before closing the VPN interface, preventing a race condition that can
            // leave the VPN icon in the status bar after stopping the service.
            try {
                Thread.sleep(100)
            } catch (e: InterruptedException) {
                LogUtil.w(AppConfig.TAG, "StartCore-VPN: Sleep interrupted", e)
            }
            try {
                if (::mInterface.isInitialized) {
                    mInterface.close()
                    LogUtil.i(AppConfig.TAG, "StartCore-VPN: VPN interface closed")
                }
            } catch (e: Exception) {
                LogUtil.e(AppConfig.TAG, "StartCore-VPN: Failed to close interface", e)
            }
        }
    }
'''
    new_vpn_stop = '''    private fun stopAllService(isForced: Boolean = true) {
//        val configName = defaultDPreference.getPrefString(PREF_CURR_CONFIG_GUID, "")
//        val emptyInfo = VpnNetworkInfo()
//        val info = loadVpnNetworkInfo(configName, emptyInfo)!! + (lastNetworkInfo ?: emptyInfo)
//        saveVpnNetworkInfo(configName, info)
        isRunning = false
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            try {
                connectivity.unregisterNetworkCallback(defaultNetworkCallback)
            } catch (e: Exception) {
                LogUtil.w(AppConfig.TAG, "StartCore-VPN: Failed to unregister callback", e)
            }
        }
        tun2SocksService?.stopTun2Socks()
        tun2SocksService = null
        RootLanSharing.stopClientSharing(this)

        // Keep upstream's proven order: stop the core first, then stopSelf
        // before closing the TUN FD. stopCoreLoop is now synchronous, so the
        // old fixed sleep is unnecessary and the next candidate cannot inherit
        // an old listener/port that is still shutting down.
        val coreStopped = CoreServiceManager.stopCoreLoop()
        if (!coreStopped) {
            LogUtil.e(AppConfig.TAG, "StartCore-VPN: Core did not stop before timeout")
        }
        if (isForced) {
            stopSelf()
            try {
                if (::mInterface.isInitialized) {
                    mInterface.close()
                    LogUtil.i(AppConfig.TAG, "StartCore-VPN: VPN interface closed")
                }
            } catch (e: Exception) {
                LogUtil.e(AppConfig.TAG, "StartCore-VPN: Failed to close interface", e)
            }
        }
    }
'''
    vpn = _replace_kotlin_function(
        vpn,
        r"^[ \t]*private[ \t]+fun[ \t]+stopAllService[ \t]*\([ \t]*isForced[ \t]*:[ \t]*Boolean[ \t]*=[ \t]*true[ \t]*\)[ \t]*\{",
        new_vpn_stop,
        "CoreVpnService stop ordering",
        already_marker="Core did not stop before timeout",
    )
    vpn_path.write_text(vpn, encoding="utf-8")

    vm = vm_path.read_text(encoding="utf-8")
    vm = replace_exact(
        vm,
        "    val isRunning by lazy { MutableLiveData<Boolean>() }\n",
        "    val isRunning by lazy { MutableLiveData<Boolean>() }\n"
        "    val runningServerGuid by lazy { MutableLiveData<String?>() }\n"
        "    val coreStartError by lazy { MutableLiveData<String?>() }\n",
        "MainViewModel running GUID LiveData",
    )
    vm = replace_exact(
        vm,
        "        isRunning.value = false\n        val mFilter = IntentFilter(AppConfig.BROADCAST_ACTION_ACTIVITY)\n",
        "        isRunning.value = false\n        runningServerGuid.value = null\n        coreStartError.value = null\n        val mFilter = IntentFilter(AppConfig.BROADCAST_ACTION_ACTIVITY)\n",
        "MainViewModel initial running GUID",
    )
    new_vm_on_receive = r'''        override fun onReceive(ctx: Context?, intent: Intent?) {
            when (intent?.getIntExtra("key", 0)) {
                AppConfig.MSG_STATE_RUNNING -> {
                    coreStartError.value = null
                    runningServerGuid.value = intent.getStringExtra("content")?.trim()?.takeIf { it.isNotBlank() }
                    isRunning.value = true
                }
                AppConfig.MSG_STATE_NOT_RUNNING -> {
                    runningServerGuid.value = null
                    isRunning.value = false
                }

                AppConfig.MSG_STATE_START_SUCCESS -> {
                    getApplication<AngApplication>().toastSuccess(R.string.toast_services_success)
                    coreStartError.value = null
                    runningServerGuid.value = intent.getStringExtra("content")?.trim()?.takeIf { it.isNotBlank() }
                    isRunning.value = true
                }
                AppConfig.MSG_STATE_START_FAILURE -> {
                    val errorMessage = intent.getStringExtra("content")
                    if (!errorMessage.isNullOrBlank()) {
                        getApplication<AngApplication>().toastError(errorMessage)
                    } else {
                        getApplication<AngApplication>().toastError(R.string.toast_services_failure)
                    }
                    coreStartError.value = errorMessage?.trim()?.takeIf { it.isNotBlank() }
                        ?: "Xray core start failed"
                    runningServerGuid.value = null
                    isRunning.value = false
                }
                AppConfig.MSG_STATE_STOP_SUCCESS -> {
                    runningServerGuid.value = null
                    isRunning.value = false
                }

                AppConfig.MSG_MEASURE_DELAY_SUCCESS -> {
                    updateTestResultAction.value = intent.getStringExtra("content")
                }

                AppConfig.MSG_MEASURE_CONFIG_SUCCESS -> {
                    val content = intent.getStringExtra("content")
                    updateListAction.value = getPosition(content ?: "")
                }
                AppConfig.MSG_MEASURE_CONFIG_NOTIFY -> {
                    val content = intent.getStringExtra("content")
                    updateTestResultAction.value =
                        getApplication<AngApplication>().getString(R.string.connection_runing_task_left, content)
                }
                AppConfig.MSG_MEASURE_CONFIG_FINISH -> {
                    val content = intent.getStringExtra("content")
                    if (content == "0") {
                        onTestsFinished()
                    }
                }
            }
        }
'''
    vm = _replace_kotlin_function(
        vm,
        r"^[ \t]*override[ \t]+fun[ \t]+onReceive[ \t]*\([ \t]*ctx[ \t]*:[ \t]*Context\?[ \t]*,[ \t]*intent[ \t]*:[ \t]*Intent\?[ \t]*\)[ \t]*\{",
        new_vm_on_receive,
        "MainViewModel runtime identity receiver",
        already_marker='coreStartError.value = errorMessage?.trim()?.takeIf { it.isNotBlank() }',
    )
    vm_path.write_text(vm, encoding="utf-8")

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

    # Kotlin is single-source: reviewed files in android-source/ are copied
    # directly into the pinned upstream checkout. Only small XML assets without
    # canonical files remain embedded above for portable CI generation.
    plain_overrides = {
        java_dir / "BlueVpnHomeActivity.kt": ROOT / "android-source/BlueVpnHomeActivity.kt",
        bluevpn_dir / "BlueVpnAccountManager.kt": ROOT / "android-source/BlueVpnAccountManager.kt",
        bluevpn_dir / "BlueVpnAdsCarouselView.kt": ROOT / "android-source/BlueVpnAdsCarouselView.kt",
        bluevpn_dir / "BlueVpnUpdateManager.kt": ROOT / "android-source/BlueVpnUpdateManager.kt",
        java_dir / "BlueVpnUpdateInstallActivity.kt": ROOT / "android-source/BlueVpnUpdateInstallActivity.kt",
        bluevpn_dir / "BlueVpnUpdateFileProvider.kt": ROOT / "android-source/BlueVpnUpdateFileProvider.kt",
        bluevpn_dir / "BlueVpnLocationUtil.kt": ROOT / "android-source/BlueVpnLocationUtil.kt",
        bluevpn_dir / "BlueVpnExperience.kt": ROOT / "android-source/BlueVpnExperience.kt",
        bluevpn_dir / "BlueVpnTheme.kt": ROOT / "android-source/BlueVpnTheme.kt",
        bluevpn_dir / "BlueVpnAi.kt": ROOT / "android-source/BlueVpnAi.kt",
        bluevpn_dir / "BlueVpnLiveReporter.kt": ROOT / "android-source/BlueVpnLiveReporter.kt",
        bluevpn_dir / "BlueVpnBootstrap.kt": ROOT / "android-source/BlueVpnBootstrap.kt",
        bluevpn_dir / "BlueVpnEngineManager.kt": ROOT / "android-source/BlueVpnEngineManager.kt",
        bluevpn_dir / "BlueVpnRuntimeGate.kt": ROOT / "android-source/BlueVpnRuntimeGate.kt",
        bluevpn_dir / "BlueVpnEntitlement.kt": ROOT / "android-source/BlueVpnEntitlement.kt",
        bluevpn_dir / "BlueVpnSmartSelector.kt": ROOT / "android-source/BlueVpnSmartSelector.kt",
        bluevpn_dir / "BlueVpnTapsellManager.kt": ROOT / "android-source/BlueVpnTapsellManager.kt",
        bluevpn_dir / "BlueVpnSingBoxProcess.kt": ROOT / "android-source/BlueVpnSingBoxProcess.kt",
        bluevpn_dir / "BlueVpnProfileManager.kt": ROOT / "android-source/BlueVpnProfileManager.kt",
        bluevpn_dir / "BlueVpnRouteIntelligence.kt": ROOT / "android-source/BlueVpnRouteIntelligence.kt",
        bluevpn_dir / "BlueVpnSubscriptionIntelligence.kt": ROOT / "android-source/BlueVpnSubscriptionIntelligence.kt",
        bluevpn_dir / "BlueVpnSingBoxProfileCompiler.kt": ROOT / "android-source/BlueVpnSingBoxProfileCompiler.kt",
        java_dir / "BlueVpnAiActivity.kt": ROOT / "android-source/BlueVpnAiActivity.kt",
        java_dir / "BlueVpnServersActivity.kt": ROOT / "android-source/BlueVpnServersActivity.kt",
        java_dir / "BlueVpnSubscriptionsActivity.kt": ROOT / "android-source/BlueVpnSubscriptionsActivity.kt",
        java_dir / "BlueVpnSettingsActivity.kt": ROOT / "android-source/BlueVpnSettingsActivity.kt",
    }
    for target, source in plain_overrides.items():
        if not source.exists():
            raise RuntimeError(f"Canonical BlueVPN source is missing: {source}")
        shutil.copy2(source, target)

    engine_source = (bluevpn_dir / "BlueVpnEngineManager.kt").read_text(encoding="utf-8")
    if "CoreServiceManager" not in engine_source:
        raise RuntimeError("BlueVPN engine boundary was not generated")
    if "startVServiceExact" not in engine_source:
        raise RuntimeError("BlueVPN exact-candidate runtime start was not generated")
    if "CoreServiceManager" in (java_dir / "BlueVpnHomeActivity.kt").read_text(encoding="utf-8"):
        raise RuntimeError("BlueVPN UI still depends directly on CoreServiceManager")

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
        "BlueVPN Android build uses the v2rayNG 2.2.6 compatibility bridge and a pinned sing-box v1.13.16 native runtime under GNU GPL v3.\n"
        "Xray-core is provided through AndroidLibXrayLite under MPL 2.0.\n"
        "Build scripts and modifications are in the BlueVPN repository.\n"
        "Upstream sources: https://github.com/2dust/v2rayNG and https://github.com/SagerNet/sing-box\n",
        encoding="utf-8",
    )

def main() -> None:
    if not APP.exists():
        raise RuntimeError("Upstream project not found at upstream/V2rayNG")
    patch_build_gradle()
    patch_strings()
    patch_manifest()
    patch_app_config()
    patch_legacy_tls_profiles()
    patch_shadowsocks_transport_queries()
    patch_v2rayng_runtime_lifecycle()
    inject_bootstrap()
    inject_bluevpn_home()
    generate_icons()
    add_source_notice()
    print("BlueVPN branding applied successfully.")
    print(f"Package: {CONFIG['application_id']}")
    print(f"Version: {CONFIG['version_name']} ({CONFIG['version_code']})")

if __name__ == "__main__":
    main()
