# BlueVPN Manager v1.2.2 — Auto Migration Runner

این Patch انتقال Railway → WordPress را خودکار می‌کند.

- ادامه Batchها هر یک دقیقه بدون کلیک دستی
- Retry خودکار خطاهای موقت
- `ai_connection_events` با Batch هزار‌تایی
- Resync افزایشی برای تاریخچه بزرگ `ai_connection_events` از آخرین ID محلی
- شروع خودکار Resync نهایی بعد از انتقال اولیه
- مقایسه خودکار PostgreSQL/MySQL و فعال‌کردن `ready_for_cutover` بعد از برابرشدن
- Dual Sync بعد از آماده‌شدن Cutover نیز خودکار توسط Runner تکمیل می‌شود

فایل‌های داخل ZIP را در ریشه Repository فعلی Merge/Upload و Commit کنید.
Railway را تا Cutover نهایی روشن نگه دارید.
