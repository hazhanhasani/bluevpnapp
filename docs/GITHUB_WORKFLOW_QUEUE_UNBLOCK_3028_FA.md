# رفع صف GitHub Actions در BlueVPN 3.0.28

نسخه 3.0.27 به‌اشتباه `queue: max` را همراه با `cancel-in-progress: false` فعال کرده بود. نتیجه این بود که هر Push جدید به‌جای جایگزینی اجرای قبلی، پشت Runهای قدیمی در صف قرار می‌گرفت.

نسخه 3.0.28 تنظیم پایدار قبلی را بازمی‌گرداند:

```yaml
concurrency:
  group: bluevpn-release-${{ github.ref }}
  cancel-in-progress: true
```

با این تنظیم فقط جدیدترین Build هر شاخه باقی می‌ماند. این اصلاح مشکل صف داخلی Workflow را برطرف می‌کند؛ اما در صورت اختلال سراسری Hosted Runnerهای GitHub، شروع Job همچنان به بازیابی سرویس GitHub وابسته است.
