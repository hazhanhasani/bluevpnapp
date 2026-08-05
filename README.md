# BlueVPN 2.1.0

نسخه Premium با طراحی جدید، Smart Connect سه‌حالته، علاقه‌مندی‌ها، امتیاز سلامت و تاریخچه اتصال.

> انتشار اول این شاخه: `2.1.0` — انتشارهای بعدی: `2.1.1`، `2.1.2` و ...

# BlueVPN 0.4.1 — Complete Project

این بسته شامل تمام اجزای فعال پروژه است:

- پنل مدیریت تحت وب روی Railway
- PostgreSQL برای نگهداری تنظیمات
- ربات دائمی نصب خودکار ZIP
- مدیریت Build و دریافت APK از داخل تلگرام
- GitHub Actions برای ساخت APK امضاشده
- رابط اختصاصی BlueVPN
- دسته‌بندی سرورها براساس لوکیشن
- انتخاب هوشمند کم‌پینگ‌ترین سرور
- نمایش پینگ، مدت اتصال، سرعت دانلود و آپلود
- نمایش حجم و زمان باقی‌مانده اشتراک
- پشتیبانی از لینک Subscription پاسارگارد
- موتور اتصال مبتنی بر v2rayNG/Xray

## نصب از طریق ربات

فایل `bluevpn-complete-v0.4.0.zip` را بدون استخراج برای ربات BlueVPN ارسال کنید.

## پنل Railway

```text
https://bluevpnapp-production.up.railway.app/admin
```

Variables ضروری Railway در فایل `.env.example` فهرست شده‌اند.

## ساخت APK

پس از Push، Workflow زیر خودکار اجرا می‌شود:

```text
.github/workflows/build-apk.yml
```

خروجی در GitHub Actions Artifacts و در صورت تنظیم Secrets، در تلگرام ارسال می‌شود.

## امضای دائمی

کلید امضا داخل این ZIP وجود ندارد. Secretهای امضا باید یک بار در GitHub تنظیم شوند.
از نسخه امضاشده 0.4.0 به بعد، بروزرسانی‌ها بدون حذف برنامه نصب می‌شوند.

## امنیت

- لینک اختصاصی مشتری را داخل سورس ثابت نکنید.
- فایل Keystore را در GitHub آپلود نکنید.
- مخزن را Private نگه دارید.
- رمزها فقط در Railway Variables و GitHub Secrets باشند.

## مجوز

بخش Android بر پایه v2rayNG و تحت GNU GPL v3 ساخته می‌شود.
فایل `LICENSE` و اسکریپت تغییرات در مخزن قرار دارند.


## اصلاح پایداری ربات در 0.4.1

Railway فقط در صورت تغییر فایل‌های سرور یا Dockerfile دوباره Deploy می‌شود.
بنابراین Commitهای مربوط به Android و GitHub Actions دیگر ربات تلگرام را
وسط فرایند ساخت APK قطع نمی‌کنند.


## BlueVPN 2.2.1

GuardCore Provider و موتور Multi Provider برای PasarGuard، Marzban و GuardCore اضافه شده است. خروجی محصول فعلاً فقط Android است.


## GuardCore دستی 2.2.1
PasarGuard و Marzban خودکار هستند؛ GuardCore با تأیید ادمین در تلگرام و Paste لینک Subscription به اشتراک تجمیعی اضافه می‌شود.
