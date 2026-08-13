# BlueVPN — اطلاعیه مجوز و منابع بالادستی

بخش Android هنگام اجرای GitHub Actions از منابع زیر استفاده می‌کند:

- v2rayNG نسخه `2.2.6` به‌عنوان لایه سازگاری موقت — GNU GPL v3
- sing-box نسخه `v1.13.16` به‌صورت Native Runtime ایزوله — GNU GPL v3 یا نسخه‌های بعدی
- Xray-core از طریق AndroidLibXrayLite — Mozilla Public License 2.0

در این مرحله `libbox.aar` کنار `libv2ray.aar` قرار نمی‌گیرد. sing-box به‌صورت فایل Native مستقل ساخته می‌شود تا کلاس‌ها و Runtime تکراری gomobile وارد یک APK نشوند.

اسکریپت تغییرات BlueVPN در `scripts/prepare_android.py` و Validator مهاجرت در `scripts/validate_release.py` قرار دارد.

BlueVPN وابسته یا مورد تأیید توسعه‌دهندگان رسمی v2rayNG، sing-box یا Xray نیست. نام‌ها و نشان‌های پروژه‌های بالادستی متعلق به صاحبان همان پروژه‌ها هستند.
