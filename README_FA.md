# اصلاح Auto Version برای BlueVPN Manager

فایل `.github/workflows/bluevpn-manager-release.yml` را جایگزین Workflow فعلی کنید.

از این به بعد با هر Commit که فایل‌های `wordpress/bluevpn-manager/**` را تغییر دهد:

1. آخرین Tag از نوع `bluevpn-manager-vX.Y.Z` خوانده می‌شود.
2. اگر نسخه سورس از Release فعلی بالاتر نباشد، Patch خودکار یکی زیاد می‌شود.
3. `Version:`، ثابت `BLUEVPN_MANAGER_VERSION` و `readme.txt` هم‌زمان اصلاح می‌شوند.
4. تغییر نسخه خودکار روی `main` Commit می‌شود.
5. ZIP افزونه ساخته می‌شود.
6. Tag/Release جدید با فایل `bluevpn-manager.zip` ساخته می‌شود.
7. Commit خودکار باعث Loop نمی‌شود.

مثال: اگر آخرین نسخه 1.2.4 باشد، تغییر بعدی افزونه به‌طور خودکار Release 1.2.5 ایجاد می‌کند.
