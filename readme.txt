=== Anar Login ===
Contributors: mehran-mrn
Tags: otp, login, sms, iran, google, passwordless
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ورود سریع وردپرس با رمز یک‌بارمصرف پیامکی، ایمیل و حساب گوگل، همراه با پنل RTL و درگاه‌های پیامکی ایرانی.

== Description ==

Anar Login یک هستهٔ مستقل و توسعه‌پذیر برای ورود بدون رمز است.

* ورود/ثبت‌نام با موبایل ایرانی و OTP
* ورود/ثبت‌نام با ایمیل و wp_mail
* ورود Google OAuth 2.0
* کاوه‌نگار، ملی پیامک، IPPanel، فراز SMS و SMS.ir
* rate limit، hash امن OTP و مصرف یک‌باره
* فرم ورود و پنل حساب کاربری واکنش‌گرا
* API و hookهای توسعه‌پذیر

== Installation ==

1. افزونه را نصب و فعال کنید.
2. منوی Anar Login را باز کنید.
3. درگاه پیامک و تنظیمات امنیتی را ذخیره کنید.
4. `[anar_login]` و `[anar_account]` را در صفحات دلخواه قرار دهید.

== Frequently Asked Questions ==

= آیا کد OTP خام در دیتابیس ذخیره می‌شود؟ =

خیر. کد با password hashing استاندارد وردپرس ذخیره و پس از اولین استفاده باطل می‌شود.

= آیا با افزونه SMTP سازگار است؟ =

بله. ایمیل از wp_mail ارسال می‌شود.

= چگونه درگاه تازه اضافه کنم؟ =

قرارداد SmsProviderInterface را پیاده‌سازی و آن را با فیلتر anar_login_sms_providers ثبت کنید.

== Changelog ==

= 0.1.0 =

* هسته OTP و REST API
* پنج درگاه پیامکی ایرانی
* ورود ایمیل و Google OAuth
* پنل مدیریت، فرم ورود و پنل حساب RTL
* محدودسازی درخواست و hookهای توسعه
