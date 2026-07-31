# Anar Login

ورود سریع، امن و خوش‌ساخت برای وردپرس با رمز یک‌بارمصرف پیامکی، ایمیل و حساب گوگل.

![Anar Login](assets/images/anar-logo.png)

> نسخهٔ `0.1.0` نخستین نسخهٔ پایه است. هسته، درگاه‌ها و رابط‌ها قابل‌استفاده‌اند، اما پیش از استفاده در سایت پرترافیک باید وب‌سرویس واقعی، تنظیمات ایمیل و رفتار قالب همان سایت آزمایش شوند.

## امکانات

- ورود و ثبت‌نام بدون رمز با موبایل ایرانی یا ایمیل
- ورود OAuth 2.0 با حساب Google
- پشتیبانی داخلی از کاوه‌نگار، ملی پیامک، IPPanel، فراز SMS و SMS.ir
- الگوی Strategy و فیلتر عمومی برای افزودن درگاه‌های تازه
- ذخیرهٔ کد OTP فقط به‌صورت hash، مصرف اتمیک یک‌باره و پاک‌سازی زمان‌بندی‌شده
- محدودسازی ارسال بر اساس شناسه و IP، فاصلهٔ ارسال مجدد و سقف تلاش تأیید
- پاسخ ضد user-enumeration هنگام درخواست کد
- ساخت خودکار کاربر با نقش محدود و hookهای قابل سفارشی‌سازی
- شناسایی حساب‌های موجود از متای Anar Login یا `digits_phone` برای مهاجرت نرم از Digits
- پنل مدیریت RTL، واکنش‌گرا و بدون وابستگی فرانت‌اند سنگین
- فرم ورود و پنل کاربری با JavaScript خالص
- سازگاری ارسال ایمیل با `wp_mail` و افزونه‌های SMTP
- حذف اختیاری داده‌ها هنگام Uninstall

## نیازمندی‌ها

- WordPress 6.2 یا جدیدتر
- PHP 7.4 یا جدیدتر
- HTTPS برای سایت‌های واقعی، به‌خصوص ورود گوگل
- یک الگوی خدماتی فعال در سرویس پیامکی با متغیر `code`

## نصب

1. پوشهٔ `anar-login` را در `wp-content/plugins` قرار دهید و افزونه را فعال کنید.
2. از منوی **Anar Login** تنظیمات عمومی را ذخیره کنید.
3. در تب **پیامک** درگاه و مشخصات الگوی خدماتی را وارد کنید.
4. صفحه‌ای با شورت‌کد `[anar_login]` برای ورود بسازید.
5. صفحه‌ای با شورت‌کد `[anar_account]` برای پنل کاربری بسازید و آن را در تنظیمات عمومی انتخاب کنید.

## تنظیم درگاه‌ها

مقادیر دقیق هر الگو را پنل سرویس پیامکی تعیین می‌کند. در نسخهٔ فعلی، همهٔ درگاه‌ها یک مقدار کد ارسال می‌کنند.

| سرویس | روش | تنظیمات لازم |
| --- | --- | --- |
| کاوه‌نگار | Verify Lookup | API Key، نام Template |
| ملی پیامک | BaseServiceNumber | Username، Password، Body ID |
| IPPanel | Edge Pattern API | Access Token، Sender، Pattern Code، نام متغیر |
| فراز SMS | IPPanel-compatible Legacy Pattern API | Username، Password، Sender، Pattern Code، نام متغیر |
| SMS.ir | Verify API v1 | API Key، Template ID، نام پارامتر |

در کد این افزونه `SSL verification` غیرفعال نشده است. اگر سرور در اتصال TLS مشکل دارد، زنجیرهٔ CA و پیکربندی سرور را اصلاح کنید.

## ورود گوگل

1. در Google Cloud Console یک OAuth 2.0 Client از نوع **Web application** بسازید.
2. آدرس نشان‌داده‌شده در تب **ورود گوگل** را عیناً به `Authorized redirect URIs` اضافه کنید.
3. Client ID و Client Secret را وارد و گزینهٔ ورود گوگل را فعال کنید.

Callback به شکل زیر است:

```text
https://example.com/wp-json/anar-login/v1/google/callback
```

جریان ورود از Authorization Code استفاده می‌کند، `state` با cookie امن و transient تطبیق داده می‌شود و تنها ایمیل تأییدشدهٔ Google پذیرفته می‌شود.

## شورت‌کدها

```text
[anar_login]
[anar_account]
```

`[anar_account]` برای کاربر مهمان خودکار فرم ورود را نمایش می‌دهد.

## توسعهٔ درگاه جدید

درگاه باید `Anar\Login\Messaging\SmsProviderInterface` را پیاده‌سازی کند و با فیلتر زیر ثبت شود:

```php
add_filter(
	'anar_login_sms_providers',
	function ( $providers, $settings ) {
		$providers['my-provider'] = new MyProvider( $settings );
		return $providers;
	},
	10,
	2
);
```

نمونهٔ کامل و نکات قرارداد در [راهنمای توسعهٔ درگاه](docs/custom-sms-provider.md) آمده است.

## Hookهای مهم

| Hook | نوع | کاربرد |
| --- | --- | --- |
| `anar_login_sms_providers` | filter | ثبت یا جایگزینی provider |
| `anar_login_allow_otp_request` | filter | رد یا قبول درخواست OTP |
| `anar_login_normalized_phone` | filter | تغییر سیاست نرمال‌سازی موبایل |
| `anar_login_phone_meta_keys` | filter | افزودن کلیدهای متای موبایل برای اتصال حساب موجود |
| `anar_login_otp_message` | filter | تغییر متن نهایی OTP |
| `anar_login_redirect_url` | filter | آدرس انتقال پس از ورود |
| `anar_login_new_user_role` | filter | نقش کاربر تازه |
| `anar_login_client_ip` | filter | IP واقعی در زیرساخت proxy قابل‌اعتماد |
| `anar_login_otp_sent` | action | ارسال موفق OTP |
| `anar_login_user_registered` | action | ثبت کاربر جدید |
| `anar_login_authenticated` | action | ورود موفق |
| `anar_login_phone_identity_linked` | action | اتصال امن شمارهٔ تأییدشده به حساب قدیمی |

## معماری

```text
REST Controller
    └── OtpService
        ├── Identity + RateLimiter
        ├── OtpRepository
        ├── ProviderRegistry → SmsProviderInterface
        └── UserService → WordPress users/auth cookies

GoogleAuth
    └── UserService
```

جزئیات بیشتر در [معماری و مدل امنیتی](docs/architecture.md) موجود است.

## توسعه و تست

```bash
composer install
composer test
composer phpcs
```

برای یک بررسی سریع بدون Composer:

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

CI روی PHP 7.4 و 8.3 syntax check و تست‌های واحد را اجرا می‌کند.

## حریم خصوصی

- شماره یا ایمیل برای یافتن/ساخت حساب وردپرس پردازش می‌شود.
- hash هویت در جدول OTP ذخیره می‌شود؛ خود کد OTP قابل‌بازیابی نیست.
- با فعال‌کردن Debug فقط کد خطای فنی ثبت می‌شود، نه OTP، API Key یا شماره.
- انتخاب «پاک‌سازی هنگام حذف» داده‌های اختصاصی افزونه را در Uninstall حذف می‌کند.

## مجوز

GPL-2.0-or-later
