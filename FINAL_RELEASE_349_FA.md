# BlueVPN 3.0.49 — بازگردانی Build واقعی APK

در نسخه‌های 3.0.44 تا 3.0.48 فایل GitHub Actions به اشتباه به Workflow آزمایشی کوتاهی تبدیل شده بود که فقط Checkout و Upload Artifact انجام می‌داد و Gradle را اجرا نمی‌کرد.

## اصلاحات
- بازگردانی Workflow کامل ۹۰ دقیقه‌ای ساخت APK
- دریافت سورس رسمی v2rayNG و submoduleها
- نصب Android SDK 37، NDK و Java 21
- اجرای scripts/prepare_android.py
- اجرای Gradle assemblePlaystoreRelease
- آپلود لاگ خطا در Build ناموفق
- امضای دائمی APK با Keystore
- ایجاد SHA-256 و GitHub Release
- ارسال APK امضاشده به تلگرام در صورت فعال‌بودن تنظیمات
- شکست اجباری Workflow وقتی هیچ APK تولید نشده باشد

Version: 3.0.49
Version Code: 30049
