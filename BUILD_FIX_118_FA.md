# اصلاح Build شماره 118 BlueVPN

خطای Build شماره 118 پیش از Gradle رخ داد، اما گزارش تلگرام به‌اشتباه مرحله
`persist-version-metadata` را نمایش می‌داد. مرحله واقعی ناموفق، ساخت Runtime بومی
sing-box برای `armeabi-v7a` بود.

## علت

فرمان قبلی Android/ARM را با این تنظیم می‌ساخت:

```text
CGO_ENABLED=0 GOOS=android GOARCH=arm GOARM=7
```

Android/ARM برای لینک‌کردن فایل اجرایی PIE به external linker نیاز دارد؛ بنابراین
ساخت با CGO خاموش متوقف می‌شد.

## اصلاح

- استفاده از NDK 29 و `armv7a-linux-androideabi24-clang` برای ARMv7
- استفاده از `aarch64-linux-android24-clang` برای ARM64
- فعال‌کردن `CGO_ENABLED=1` برای هر دو ABI
- ثبت مرحله واقعی `sing-box-native-build`
- ساخت `singbox-build.log` و ارسال آن در خطای تلگرام
- ذخیره دائمی مرحله `gradle-compile` برای تشخیص درست خطاهای Kotlin/Gradle
