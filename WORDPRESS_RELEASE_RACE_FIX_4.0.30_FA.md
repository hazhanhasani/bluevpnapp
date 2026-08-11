# BlueVPN 4.0.30 — WordPress Release Race & Pre-Gradle Diagnostics Fix

Build #191 با commit `3a6fc40a` قبل از Gradle متوقف شد، اما stage گزارش‌شده `persist-version-metadata` مبهم بود چون از آن مرحله تا ساخت native runtime مقدار stage تغییر نمی‌کرد و `android-build.log` نیز تا مراحل بعد ساخته نمی‌شد.

## اصلاح‌ها

- فقط `build-apk.yml` ناشر خودکار BlueVPN Manager است.
- Workflow مستقل `Release BlueVPN Manager` فقط به‌صورت `workflow_dispatch` برای بازیابی دستی باقی مانده است.
- انتشار inline در برابر race ساخت همزمان همان GitHub Release مقاوم شده است؛ اگر create همزمان شکست بخورد، Release دوباره خوانده و asset روی همان Release refresh می‌شود.
- `android-build.log` از ابتدای Workflow ساخته می‌شود.
- مراحل حساس پیش از Gradle خروجی خود را داخل همان log می‌نویسند.
- stageهای دقیق اضافه شدند: `sync-wordpress-manager`، `persist-version-metadata`، `publish-wordpress-manager-release`، `wait-wordpress-auto-update` و `checkout-android-runtime`.
- پنجره انتظار همگرایی WordPress از ۴ به ۶ دقیقه افزایش یافت.

نتیجه: خطاهای قبل از Gradle دیگر به اشتباه صرفاً `persist-version-metadata` گزارش نمی‌شوند و رقابت دو publisher برای tag/asset افزونه حذف شده است.
