BlueVPN 3.0.11 — Reliable In-App Updater
Build ID: 20260806-updater-network-fallback-v3011

فایل ZIP را از طریق ربات Deploy نصب کنید تا ابتدا در GitHub Commit و سپس APK ساخته شود.

اصلاحات این نسخه:
- رفع خطای Binding socket to network ... EPERM
- مجوز CHANGE_NETWORK_STATE برای مسیر فیزیکی شبکه
- fallback خودکار از اینترنت مستقیم به مسیر عادی/تونل فعال
- پیام خطای فارسی به‌جای خطای خام اندروید
- حفظ BlueAI Time Decay نسخه 3.0.10

فایل‌های اصلی تغییرکرده:
- scripts/prepare_android.py
- scripts/validate_generated_android.py
- android-source/BlueVpnUpdateManager.kt
- release.json
- branding/app.json
