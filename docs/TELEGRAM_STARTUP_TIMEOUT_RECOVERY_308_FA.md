# BlueVPN 3.0.8 — رفع Timeout راه‌اندازی ربات Telegram

## علت

`Application.initialize()` برای اعتبارسنجی توکن، متد `get_me()` را اجرا می‌کند. Timeout پیش‌فرض HTTPXRequest در python-telegram-bot کوتاه است و روی مسیرهای موقتاً کند Railway می‌تواند `telegram.error.TimedOut` بدهد، حتی وقتی چند ثانیه بعد Telegram در دسترس است.

## اصلاحات

- Request مستقل برای Bot API و Long Polling با HTTP/1.1
- Connect/Read/Write/Pool timeout صریح
- Retry پلکانی با سقف ۱۲۰ ثانیه
- پنج Bootstrap Retry برای polling
- Cleanup قطعی Application در شکست نیمه‌کاره initialize
- اجرای صریح `bot_post_init` در چرخه سفارشی برنامه
- ارسال هشدار فقط بعد از سه خطای شبکه‌ای متوالی
- جداسازی خطای Telegram از تشخیص دیتابیس

## متغیرهای اختیاری

```env
TELEGRAM_CONNECT_TIMEOUT=35
TELEGRAM_READ_TIMEOUT=35
TELEGRAM_GET_UPDATES_READ_TIMEOUT=65
TELEGRAM_WRITE_TIMEOUT=35
TELEGRAM_POOL_TIMEOUT=35
TELEGRAM_BOOTSTRAP_RETRIES=5
TELEGRAM_START_RETRY_SECONDS=10
TELEGRAM_START_RETRY_MAX_SECONDS=120
TELEGRAM_ALERT_AFTER_FAILURES=3
```

وجود `PGHOST`, `PGUSER`, `PGPASSWORD`, `PGDATABASE` برای ساخت URL داخلی PostgreSQL کافی است؛ نبود یک متغیر URL جداگانه لزوماً خطای دیتابیس نیست.
