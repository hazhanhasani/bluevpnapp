# BlueVPN 3.0.76 — Runtime Pool & BluePay Recovery

## اصلاحات اصلی

- حذف فیزیکی پروفایل‌های رایگان و اشتراک‌های قدیمی از MMKV هنگام تغییر Entitlement
- جلوگیری سه‌مرحله‌ای از اتصال به سرور خارج از پلن فعال
- انتخاب مجدد خودکار سرور پس از تغییر رایگان/Premium
- ترکیب Round-robin سرورهای پاسارگارد، مرزبان و پنل سوم برای جلوگیری از تسلط یک Provider خراب بر shortlist
- Retry امن و Idempotent ساخت فاکتور BluePay
- حذف Callback نامعتبر و استفاده از Origin واقعی Railway
- بازیابی API Keyهای رمز‌شده با `SESSION_SECRET` یا کلید قبلی
- ثبت پیام واقعی BluePay و `X-Request-ID`

## نتیجه تست

- 291 passed
- 0 failed
- 35/35 architecture checks passed
- Generated Android validation passed

## نکته انتشار

برای فعال‌شدن اصلاح BluePay، Backend Railway نیز باید از همین نسخه Deploy شود. برای اصلاح جداسازی سرورها، APK جدید باید نصب یا به‌روزرسانی شود. Build کامل APK در GitHub Actions باید تأیید شود.
