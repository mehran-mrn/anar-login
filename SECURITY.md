# Security Policy

## Supported versions

تا انتشار نسخهٔ پایدار، فقط آخرین commit شاخهٔ اصلی پشتیبانی امنیتی می‌شود.

## Reporting a vulnerability

آسیب‌پذیری را در issue عمومی همراه با secret، API key، شماره واقعی یا کد OTP منتشر نکنید. از بخش **Security advisories** مخزن GitHub و گزینهٔ **Report a vulnerability** استفاده کنید.

در گزارش این موارد را بنویسید:

- نسخهٔ WordPress، PHP و Anar Login
- گام‌های بازتولید حداقلی
- اثر امنیتی
- request/response پاک‌سازی‌شده از دادهٔ شخصی و secret

## Operational guidance

- سایت production را با HTTPS اجرا کنید.
- `WP_DEBUG_DISPLAY` را در production خاموش کنید.
- برای سایت پرترافیک persistent object cache فعال کنید.
- نقش کاربر تازه را روی کمترین سطح دسترسی نگه دارید.
- API key پیامک و Google Client Secret را دوره‌ای تعویض کنید.
- headerهای proxy را فقط از proxy شناخته‌شده بپذیرید.
