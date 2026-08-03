# اصلاح ساخت خودکار BlueVPN 0.4.1

مشکل قبلی:
هر Commit مربوط به اپلیکیشن اندروید، Railway را نیز دوباره Deploy می‌کرد.
در نتیجه ربات تلگرام وسط نصب یا انتظار برای GitHub Actions ری‌استارت می‌شد و
پیام آن روی «بررسی Secretها» یا «نصب روی GitHub» باقی می‌ماند.

اصلاح:
`railway.json` اکنون Watch Pattern دارد و Railway فقط با تغییر این مسیرها
Deploy می‌شود:

- Dockerfile
- requirements.txt
- server/**
- railway.json

تغییرات زیر دیگر Railway را ری‌استارت نمی‌کنند:

- branding/**
- scripts/**
- .github/workflows/**
- فایل‌های رابط و Build اندروید

نکته:
آپلود همین نسخه ممکن است یک بار Railway را ری‌استارت کند، چون خود
`railway.json` تغییر کرده است. پس از فعال‌شدن این نسخه، آپدیت‌های بعدی بدون
قطع‌شدن ربات ساخته و ارسال می‌شوند.
