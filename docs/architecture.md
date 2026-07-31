# معماری و مدل امنیتی

## مرزهای اصلی

`Plugin` فقط composition root است. کنترلرهای REST ورودی HTTP را تحویل سرویس‌های کاربردی می‌دهند و هیچ provider مستقیماً کاربر یا دیتابیس را تغییر نمی‌دهد.

- `OtpService`: ساخت، ارسال و تأیید challenge
- `OtpRepository`: تنها مالک جدول OTP
- `RateLimiter`: محدودیت fixed-window با transient/object cache
- `ProviderRegistry`: انتخاب Strategy پیامکی
- `UserService`: تنها سرویس ساخت کاربر و ایجاد session وردپرس
- `GoogleAuth`: state، token exchange و دریافت userinfo

## چرخهٔ OTP

1. هویت نرمال و اعتبارسنجی می‌شود.
2. محدودیت cooldown و سقف ساعتی برای هویت و IP بررسی می‌شود.
3. با `random_int` کد تولید و با `wp_hash_password` ذخیره می‌شود.
4. provider کد را ارسال می‌کند. در شکست ارسال، challenge فوراً consume می‌شود.
5. هنگام تأیید، زمان انقضا و سقف تلاش بررسی می‌شود.
6. `wp_check_password` hash را بررسی می‌کند.
7. update شرطی دیتابیس challenge را اتمیک consume می‌کند.
8. کاربر موجود وارد یا در صورت مجازبودن ساخته می‌شود.

جدول هیچ شماره یا ایمیلی نگه نمی‌دارد؛ فقط HMAC هویت ذخیره می‌شود.

## Rate limit

دو کلید مستقل استفاده می‌شوند:

- identity: سقف قابل‌تنظیم و cooldown ارسال
- IP: چهار برابر سقف هویت برای کاهش حملهٔ توزیع‌نشده

در سایت‌های پشت CDN یا reverse proxy، فقط پس از تعریف فهرست proxyهای قابل‌اعتماد، `anar_login_client_ip` را برای استخراج header مناسب فیلتر کنید. پذیرش مستقیم `X-Forwarded-For` بدون trust boundary ناامن است.

Transient برای نصب تک‌سرور مناسب است. سایت‌های پرترافیک باید persistent object cache داشته باشند یا `RateLimiter` را در نسخهٔ بعدی با storage اتمیک خارجی جایگزین کنند.

## Google OAuth

- Authorization Code flow
- state تصادفی ۲۵۶ بیتی
- تطبیق state با HttpOnly/SameSite=Lax cookie
- transient کوتاه‌عمر و اتصال state به hash آدرس IP
- redirect نهایی فقط پس از `wp_validate_redirect`
- استفاده از endpoint استاندارد OpenID Connect userinfo
- پذیرش فقط `email_verified`

## Trust و خروجی

- تمام ورودی‌ها در boundary مربوط sanitize/validate می‌شوند.
- خروجی HTML با توابع escaping وردپرس تولید می‌شود.
- درخواست provider از WordPress HTTP API و TLS verification پیش‌فرض استفاده می‌کند.
- جزئیات پاسخ provider فقط در `WP_Error::data` قرار دارد و endpoint عمومی متن عمومی را برمی‌گرداند؛ در محیط production نمایش debug وردپرس باید خاموش باشد.

## محدودیت‌های نسخهٔ 0.1

- اتصال/تغییر شماره یا ایمیل از داخل پنل هنوز workflow جداگانه ندارد.
- rate limit مبتنی بر transient است.
- providerها باید با حساب واقعی و الگوی همان مشتری smoke test شوند؛ پاسخ سرویس‌ها ممکن است با نسخه یا قرارداد حساب فرق کند.
- multisite و headless cross-origin هنوز سناریوی رسمی نیستند.

## منابع API providerها

- IPPanel Edge Pattern API: <https://ippanelcom.github.io/Edge-Document/fa/docs/send/pattern/>
- Kavenegar REST Verify Lookup: <https://www.postman.com/kavenegarrestapi/kavenegar-s-public-workspace/documentation/v5ppwag/kavenegar-rest-api>
