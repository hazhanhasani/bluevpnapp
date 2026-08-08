# انتشار BlueVPN 3.0.40

این نسخه خطای `lintVitalPlaystoreRelease` با قانون `ExtraTranslation` را رفع می‌کند.

## اصلاح انجام‌شده

سه کلید زیر در نسخه 3.0.39 فقط داخل `values-fa/strings.xml` تولید می‌شدند:

- `service_started`
- `service_stopped`
- `notification_service_running`

اکنون مولد Android برای هر ترجمه، وجود کلید متناظر در locale پیش‌فرض را تضمین می‌کند. متن‌های موجود upstream دست‌نخورده می‌مانند و فقط کلیدهای غایب با متن تمیز BlueVPN افزوده می‌شوند.

## محافظت رگرسیون

- توقف فوری مولد در صورت نبودن کلید پیش‌فرض
- تست برابری رشته‌های فارسی و پیش‌فرض
- اعتبارسنجی XML تولیدشده
- حفظ اصلاح `isSingleLine` نسخه 3.0.39
