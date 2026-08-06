# BlueVPN 3.0.28 — رفع صف اشتباه GitHub Actions

در نسخه 3.0.27 فایل Workflow به‌اشتباه `queue: max` و `cancel-in-progress: false` داشت. این ترکیب باعث می‌شد اجرای تازه پشت Buildهای قدیمی و در انتظار قرار بگیرد و چند Run هم‌زمان در صف بماند.

در نسخه 3.0.28 رفتار پایدار قبلی بازگردانده شده است:

```yaml
concurrency:
  group: bluevpn-release-${{ github.ref }}
  cancel-in-progress: true
```

بنابراین با هر آپلود، اجرای قدیمی همان شاخه کنار گذاشته می‌شود و فقط جدیدترین Build باقی می‌ماند. Runner همچنان `ubuntu-latest` است. قابلیت ورود با شماره تماس و OTP فراز اس‌ام‌اس بدون تغییر حفظ شده است.
