# اصلاح Build #129 — BlueVPN 3.0.81

خطای Kotlin در `SubscriptionUpdater.kt` به دلیل نبود کلاس `com.google.common.util.concurrent.ListenableFuture` روی compile classpath رخ می‌داد. `RemoteWorkManager` این نوع را مستقیماً در امضای متدهای cancel/enqueue استفاده می‌کند.

اصلاح انجام‌شده:

- افزودن صریح `com.google.guava:guava:33.6.0-android` به Gradle تولیدی Android.
- حفظ `RemoteWorkManager` و رفتار Background Subscription Sync؛ هیچ fallback یا حذف قابلیت انجام نشده است.
- عدم Force کردن `com.google.guava:listenablefuture:1.0` برای جلوگیری از تعارض با artifact سازگاری `9999.0-empty-to-avoid-conflict-with-guava`.
- اضافه‌شدن تست Regression مخصوص Build #129.

اعتبارسنجی محلی:

- 323 تست موفق، 0 ناموفق.
- 35 بررسی معماری موفق، 0 ناموفق.
- Generated Android validation موفق.
- Build کامل Gradle/NDK به GitHub Actions واگذار شده است.
