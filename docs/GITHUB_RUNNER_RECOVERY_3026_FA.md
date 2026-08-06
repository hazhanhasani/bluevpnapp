# بازیابی Runner گیت‌هاب — BlueVPN 3.0.26

## علت
اجرای GitHub Actions ممکن است پیش از شروع هر Step با پیام `The job was not acquired by Runner of type hosted` تمام شود. در این حالت کد Android هنوز اجرا نشده است.

## تغییرات
- Runner پیش‌فرض از `ubuntu-latest` به `ubuntu-22.04` پین شد.
- `cancel-in-progress` غیرفعال شد.
- صف concurrency روی `queue: max` قرار گرفت تا آپلود جدید Build منتظر را حذف نکند.
- ورودی `runner` برای `workflow_dispatch` اضافه شد.
- ربات Deploy تشخیص می‌دهد Job هیچ Runner و هیچ Step اجراشده‌ای نداشته است.
- در این وضعیت، همان Commit فقط یک بار روی `ubuntu-24.04` دوباره Dispatch می‌شود.
- هنگام جست‌وجوی Run، شناسه‌های قبلی رد می‌شوند تا Run شکست‌خورده قدیمی دوباره انتخاب نشود.

هیچ Secret یا متغیر جدیدی لازم نیست؛ مقادیر Runner پیش‌فرض داخلی هستند.
