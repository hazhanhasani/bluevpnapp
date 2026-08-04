# ارسال خودکار خطای Build به تلگرام

در هر شکست GitHub Actions، Workflow این موارد را برای ربات می‌فرستد:

1. پیام خلاصه شامل نسخه، شماره Build، Commit، شاخه و لینک Run
2. مهم‌ترین خط‌های `e:`، `error:`، `unresolved reference` و خطای Task
3. فایل کامل `android-build.log`
4. فایل کوچک `telegram-build-error.txt` برای ارسال آسان در گفتگو

این مرحله با `if: failure()` در انتهای Job قرار دارد؛ بنابراین خطای Build،
امضا، Checksum یا Artifact را نیز گزارش می‌کند.

Secretهای مورد استفاده همان موارد قبلی هستند:

- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_CHAT_ID`

اگر این دو Secret تنظیم نباشند، Workflow فقط Artifact و Summary گیت‌هاب را
نگه می‌دارد و ارسال تلگرام را رد می‌کند.
