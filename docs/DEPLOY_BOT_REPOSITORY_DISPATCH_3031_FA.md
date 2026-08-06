# رفع نمایش Build قدیمی و ساخته‌نشدن Run جدید — BlueVPN 3.0.31

## مشکل

Commit جدید روی شاخه `main` ثبت می‌شد، اما GitHub Actions Run تازه‌ای ایجاد نمی‌کرد و ربات همچنان Build شماره 74 را به‌عنوان آخرین Build نمایش می‌داد.

## علت

روش `workflow_dispatch` به مجوز جداگانه `Actions: write` نیاز دارد. توکن ربات از قبل برای آپلود پروژه مجوز `Contents: write` دارد، اما ممکن است مجوز Actions روی آن فعال نباشد. در نتیجه Commit ثبت می‌شد ولی درخواست Build قابل اتکا نبود.

## راه‌حل

1. رویداد `repository_dispatch` با نوع `bluevpn_build` به Workflow اضافه شد.
2. ربات SHA دقیق شاخه را در `client_payload.target_sha` ارسال می‌کند.
3. Workflow همان SHA را Checkout و صحت آن را بررسی می‌کند.
4. اگر repository dispatch در ۳۰ ثانیه Run نسازد، `workflow_dispatch` خودکار امتحان می‌شود.
5. Runهای قبل از درخواست جدید فیلتر می‌شوند تا Build 74 دوباره انتخاب نشود.

## متغیرها

متغیر جدیدی لازم نیست. مقدار پیش‌فرض رویداد:

```text
GITHUB_REPOSITORY_DISPATCH_EVENT=bluevpn_build
```

تنظیم دستی آن ضروری نیست.
