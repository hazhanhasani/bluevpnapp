# اصلاح صفحه ادمین BlueVPN 0.4.6

علت خطای Not Found:
فرایند اصلی از `/opt/bluevpn_bot/run.py` اجرا می‌شد. در این حالت Python فقط
پوشه `/opt/bluevpn_bot` را در مسیر Import قرار می‌داد و پوشه پروژه `/app`
قابل Import نبود. در نتیجه `server.main` لود نمی‌شد و برنامه اضطراری اجرا
می‌شد که مسیر `/admin/login` نداشت.

اصلاح:
Railway اکنون سرویس را با فرمان زیر اجرا می‌کند:

`/bin/sh -c "export PYTHONPATH=/app; exec python /opt/bluevpn_bot/run.py"`

پس از Deploy:
- `/` به `/admin` منتقل می‌شود.
- `/admin/login` صفحه ورود را نمایش می‌دهد.
- `/health` وضعیت دیتابیس را نمایش می‌دهد.
- در صورت مشکل PostgreSQL، پنل با SQLite موقت باز می‌ماند.
