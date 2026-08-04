BlueVPN GitHub Release Autopilot 1.0.19

این ZIP را مستقیم برای ربات Deploy ارسال کن.

تغییرات:
- حذف ورود دستی شماره نسخه
- حذف ورود دستی Version Code
- حذف ورود دستی لینک APK
- حذف ورود دستی عنوان و متن آپدیت
- نسخه Android خودکار: 1.0.<GitHub Run Number>
- Version Code خودکار: 10000 + Run Number
- انتشار خودکار APK امضاشده در GitHub Releases
- ساخت خودکار release-manifest.json
- دریافت نسخه و لینک‌ها از آخرین GitHub Release
- انتخاب APK متناسب با arm64-v8a یا armeabi-v7a
- Cache پنج‌دقیقه‌ای GitHub در Backend
- حفظ آخرین نسخه موفق هنگام خطای موقت GitHub
- Build Trigger قبلی با Commit و Push حفظ شده است

Project Version: 1.0.19
Build ID: 20260804141957
