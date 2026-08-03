# GitHub Actions Secrets

این Secretها باید فقط در مسیر زیر قرار بگیرند:

`GitHub Repository → Settings → Secrets and variables → Actions`

## امضای دائمی Android

- `ANDROID_KEYSTORE_BASE64`
- `ANDROID_KEYSTORE_PASSWORD`
- `ANDROID_KEY_ALIAS`
- `ANDROID_KEY_PASSWORD`

مقادیر این چهار مورد داخل Signing Kit جداگانه BlueVPN قرار دارند.
فایل JKS یا رمزهای آن را داخل مخزن یا ZIP پروژه آپلود نکنید.

## تحویل APK به تلگرام

- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_CHAT_ID`

توکن تلگرام و آیدی عددی مدیر را فقط به‌صورت Secret وارد کنید.
