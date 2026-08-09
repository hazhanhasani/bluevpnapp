# اصلاح Build شماره 119

## علت واقعی

فرایند پیش از Gradle در ساخت sing-box برای `android/arm` متوقف می‌شد:

```text
android/arm requires external (cgo) linking, but cgo is not enabled
```

Android/armv7 در Go همیشه به لینک خارجی cgo وابسته است. برای جلوگیری از توقف کل انتشار، runtime مستقل sing-box فقط برای `arm64-v8a` ساخته می‌شود. APK سی‌ودوبیتی `armeabi-v7a` همچنان با Xray کار می‌کند و مدیر موتور در نبود فایل sing-box به‌صورت خودکار Xray را انتخاب می‌کند.

## تغییرات

- حذف Build معیوب sing-box برای armv7
- ساخت arm64 بدون cgo و بدون `-buildmode=pie` اجباری
- نگه‌داشتن APK armv7 با Xray fallback
- ثبت مرحله Build در `.bluevpn-build-stage`
- ارسال `singbox-build.log` در خطاهای قبل از Gradle
- اضافه‌شدن تست Regression مخصوص Build 119
