BlueVPN Force Deploy 1.0.7

این فایل را مستقیم برای ربات Deploy ارسال کنید.

این بسته حتماً تغییر جدید دارد:
- نسخه 1.0.7
- Build ID: 20260804092417
- فایل جدید deployment-marker.json
- زمان ساخت جدید داخل release.json

بنابراین ربات و GitHub نباید پیام «تغییری نداشت» نمایش دهند.

بعد از Deploy:
- /startup-status باید version=1.0.7 باشد.
- /health باید version=1.0.7 باشد.
- قابلیت تشخیص خودکار PostgreSQL نسخه قبلی حفظ شده است.

APK جدید لازم نیست.
