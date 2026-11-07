# انار — ورود پیامکی، بازیابی خرید و بازاریابی ووکامرس

راهنمای فارسی نسخهٔ ۰.۸.۲. ترجمهٔ رابط همراه افزونه نصب می‌شود؛ برای استفاده، زبان سایت یا کاربر را فارسی انتخاب کنید.

## معرفی

انار مجموعه‌ای از ابزارهای ورود، پیگیری سفارش و ارتباط با مشتری برای وردپرس و ووکامرس است. از ورود پیامکی و بازیابی رمز تا یادآوری سبد رهاشده و کمپین‌های فروش، قابلیت‌های مورد نیاز فروشگاه را در یک افزونه انتخاب و مدیریت کنید.

### ورود و حساب کاربری
* ورود و ثبت‌نام با شمارهٔ همراه ایرانی و کد پیامکی، کد تأیید ایمیل یا حساب گوگل.
* بازیابی رمز عبور با پیامک برای شمارهٔ از قبل متصل به حساب و تأیید دومرحله‌ای اختیاری.
* فرم ورود پاپ‌آپ و اتصال به حساب کاربری و تسویه‌حساب ووکامرس.

### پیگیری خرید و ارتباط با مشتری
* ثبت خودکار سبد رهاشده، سه مرحلهٔ یادآوری و کوپن اختصاصی یک‌بارمصرف.
* اعلان وضعیت سفارش، کد رهگیری مرسوله و هشدار خرید یا پرداخت ناموفق برای مدیر.
* پیام خرید اول، بازگشت مشتری، مشتری ویژه، تولد و هدیهٔ خرید بعدی.
* پیش‌بینی خرید مجدد بر اساس سابقه، پیشنهاد کالا و ارسال گروهی هدفمند و زمان‌بندی‌شده.
* اطلاع موجودی مجدد، نظرسنجی خرید، دیدگاه محصول و معرفی دوستان.
* صورتحساب قابل چاپ، ده بازی فروشگاهی و گزارش سبدهای بازگشتی، پیام‌ها و کمپین‌ها.

### انتخاب قابلیت‌ها و رابط فارسی
بخش‌های فعال در ابتدای صفحه قرار می‌گیرند؛ سایر قابلیت‌ها پایین‌تر، همراه راهنما و کلید فعال‌سازی باقی می‌مانند. سرویس‌ها و فایل‌های نمایشی هر ماژول بر اساس نیاز بارگذاری می‌شوند. راهنمای کوتاه و علامت اطلاعات در هر بخش، مراحل تنظیم را توضیح می‌دهد.

**ترجمهٔ فارسی همراه افزونه است.** روی وردپرس یا حساب کاربری فارسی، رابط راست‌به‌چپ و ترجمه بدون نصب بستهٔ جداگانه فعال می‌شود. رابط انگلیسی نیز در دسترس است.

**تمام امکانات همیشه رایگان‌اند.** هزینهٔ احتمالی حساب و پیامک را شرکت پیامکی دریافت می‌کند. پنل‌های پشتیبانی‌شده: کاوه‌نگار، ملی‌پیامک، IPPanel، پنل‌چی، مکث‌اس‌ام‌اس (MaxSMS)، فراز پیامک و SMS.ir؛ درگاه HTTPS سفارشی نیز وجود دارد.

Anar Toolkit provides SMS/email/Google login, SMS password reset, cart recovery, order updates and optional customer campaigns for WooCommerce. Includes Persian RTL and English LTR interfaces. All plugin features are free; SMS provider fees are separate.

[راهنمای فارسی](https://github.com/mehran-mrn/anar-login/blob/main/guide-fa.txt) | [کد و گزارش اشکال](https://github.com/mehran-mrn/anar-login) | [MRNWP](https://mrnwp.ir/)

## نصب و راه‌اندازی

1. افزونه را از مخزن وردپرس نصب و فعال کنید. اگر نسخهٔ پیش‌انتشار با پوشهٔ anar-login دارید، ابتدا همان نسخه را غیرفعال کنید.
2. در «تنظیمات ← عمومی» زبان سایت را فارسی قرار دهید. زبان پیشخوان می‌تواند از بخش «کاربران ← شناسنامه» نیز برای هر کاربر انتخاب شود. ترجمهٔ فارسی داخل بستهٔ افزونه قرار دارد.
3. «جعبه‌ابزار انار ← مدیریت قابلیت‌ها» را باز و بخش‌های مورد نیاز را انتخاب کنید. ماژول‌های تازهٔ فروشگاه و بازاریابی در ابتدا خاموش‌اند.
4. در «تنظیمات اتصال»، حساب پنل پیامکی و الگوی تأییدشده را وارد کنید. برای ورود ایمیلی از تنظیمات ارسال ایمیل سایت استفاده می‌شود؛ ورود گوگل به برنامهٔ OAuth اختصاصی شما نیاز دارد.
5. برای صفحهٔ حساب از `[anar_account]`، ورود مستقل از `[anar_login]` و دکمهٔ پاپ‌آپ از `[anar_login_button]` استفاده کنید.
6. زمان ارسال، مخاطبان و الگوهای هر بخش فعال را تنظیم کنید. نام متغیرهای پیام باید دقیقاً با الگوی تأییدشدهٔ همان پنل مطابقت داشته باشد. الگوی ورود و کمپین را جدا تعریف کنید.
7. کد کوتاه `[anar_recover_cart]` را کنار بلوک سبد یا تسویه‌حساب قرار دهید. ثبت خودکار شمارهٔ حساب یا تسویه‌حساب به تیک جداگانه نیاز ندارد؛ حالت ثبت رضایت صریح نیز اختیاری است. لغو دریافت پیام همیشه حفظ می‌شود.
8. پیش از جایگزینی فرم ورود یا فعال‌کردن تأیید دومرحله‌ای، ارسال و ورود را آزمایش کنید. اجرای یادآوری‌ها به Action Scheduler یا WP-Cron سایت وابسته است.

Install and activate the plugin, select the required features, then configure your own provider credentials and approved templates. Set the site or user language to Persian for the bundled RTL translation. Login works without WooCommerce; store modules require WooCommerce. HTTPS is required for provider endpoints.

## پرسش‌های متداول

### آیا رابط افزونه واقعاً فارسی است؟

بله. ترجمهٔ آمادهٔ فارسی همراه بستهٔ نصب است و با زبان سایت یا زبان انتخابی کاربر بارگذاری می‌شود. فرم‌ها، تنظیمات، پیام‌های رابط و راهنماهای انار از فارسی و چینش راست‌به‌چپ پشتیبانی می‌کنند. نصب بستهٔ جداگانه لازم نیست. نام سرویس‌ها، کدهای کوتاه و شناسه‌های فنی ترجمه نمی‌شوند.

نمایش زبان‌های تأییدشده در صفحهٔ مخزن به سامانهٔ ترجمهٔ وردپرس وابسته است و با ترجمهٔ همراه افزونه تفاوت دارد. ترجمه‌های رسمی یا سفارشی نصب‌شده در پوشهٔ زبان وردپرس در اولویت هستند.

### آیا همهٔ امکانات رایگان‌اند؟

بله. نسخهٔ حرفه‌ای، اشتراک افزونه، کلید مجوز یا قفل امکانات وجود ندارد. هزینهٔ احتمالی پنل، تأیید الگو و اعتبار پیامک مربوط به سرویس‌دهندهٔ منتخب شماست. ورود ایمیلی از سرویس ایمیل فعلی سایت استفاده می‌کند.

### اگر فقط ورود پیامکی بخواهم، بقیهٔ بخش‌ها چه می‌شوند؟

فقط قابلیت‌های مورد نیاز را فعال کنید. بخش‌های غیرفعال در «سایر قابلیت‌ها» باقی می‌مانند و فایل‌های نمایشی یا پردازش اختصاصی آن‌ها اجرا نمی‌شود. غیرفعال‌سازی، تنظیمات و سوابق را پاک نمی‌کند. محدودیت کوپن‌های قبلاً صادرشده، پیوندهای لغو پیام و پاک‌سازی دوره‌ای اطلاعات همچنان معتبرند.

### سبد رهاشده و تخفیف چگونه کار می‌کنند؟

سبد استاندارد مشتری با شمارهٔ حساب متصل یا شمارهٔ تسویه‌حساب ثبت می‌شود. پس از مدت عدم فعالیت تعیین‌شده، حداکثر سه یادآوری ارسال می‌شود. مشتری با کد بازیابی، سبد یا سفارش پرداخت‌نشدهٔ واجد شرایط را بازیابی می‌کند. کوپن تولیدشده مدت‌دار، یک‌بارمصرف و محدود به شمارهٔ صورتحساب است. کالاهای ساده و متغیر با قیمت و موجودی فعلی بازیابی می‌شوند؛ بسته‌های ترکیبی، اشتراک‌ها و اطلاعات اختصاصی افزونه‌های جانبی نیازمند بررسی جداگانه‌اند.

نگاشت پیام بازیابی باید `{code}`، `{recovery_page}` و `{unsubscribe_url}` را داشته باشد. پیوند صفحهٔ بازیابی حاوی کد یا مجوز ورود به حساب نیست. در پنل‌های دارای محدودیت طول متغیر، طول نشانی‌ها را نیز بررسی کنید.

### چه اعلان‌هایی برای سفارش وجود دارد؟

اعلان پرداخت، وضعیت‌هایی مانند پردازش و تکمیل، اطلاعات مرسوله و هشدار خرید یا پرداخت ناموفق برای مدیر، کلیدهای جداگانه دارند. کد رهگیری، شرکت حمل و نشانی رهگیری را در جعبهٔ انار در ویرایش سفارش ثبت کنید. اعلان پرداخت فقط با گزارش موفق پرداخت از ووکامرس ایجاد می‌شود؛ مشاهدهٔ صفحهٔ تشکر یا تغییر دستی وضعیت به‌تنهایی تأیید پرداخت نیست. ابزارهای سفارش با HPOS کار می‌کنند.

### ارسال گروهی و نظرسنجی چه محدودیت‌هایی دارند؟

ارسال گروهی به پیش‌نمایش مخاطبان و تأیید شروع نیاز دارد؛ درون‌ریزی شماره‌ها به‌تنهایی پیامک نمی‌فرستد. سقف روزانه، فاصلهٔ پیام‌ها و امکان توقف یا لغو کار باقی‌مانده وجود دارد. پیام بازاریابی باید `{unsubscribe_url}` داشته باشد. نظرسنجی به `{action_url}` و پیام تخفیف به `{coupon_code}` نیز نیاز دارد. هدیهٔ نظرسنجی مستقل از مثبت یا منفی بودن امتیاز است و دیدگاه محصول پیش از انتشار به تأیید مدیر نیاز دارد.

### پیش‌بینی خرید و گزارش فروش چه معنایی دارند؟

پیش‌بینی یک برآورد آماری از فاصلهٔ خریدهای قبلی است؛ اگر سابقه کافی نباشد، پیش‌بینی نمایش داده نمی‌شود. گزارش‌ها از داده‌های واقعی همین سایت ساخته می‌شوند. خرید منتسب به کوپن به‌تنهایی اثبات افزایش فروش نیست. وضعیت «پذیرفته‌شده» یعنی API پنل درخواست را پذیرفته است و تحویل پیامک به گوشی را تأیید نمی‌کند. ارسال‌های با نتیجهٔ نامشخص خودکار تکرار نمی‌شوند.

### آیا برای بازیابی رمز، داشتن شمارهٔ صورتحساب کافی است؟

خیر. فقط شماره‌ای که از قبل از طریق انار یا دادهٔ هویتی سازگار به حساب متصل است می‌تواند رمز آن حساب را بازنشانی کند. کد بازنشانی از کد ورود و بازیابی سبد جداست، پنج دقیقه اعتبار دارد و تغییر رمز، نشست‌های قبلی را لغو می‌کند. ثبت‌نام عمومی نیز فقط نقش مشترک یا مشتری ووکامرس با دسترسی پایه ایجاد می‌کند.

### آیا با بلوک تسویه‌حساب و هر نوع قالب سازگار است؟

فرم ورود ووکامرس بر پایهٔ حساب کاربری و تسویه‌حساب کلاسیک است؛ افزونه مؤلفهٔ بومی ورود برای Checkout Block ندارد. کدهای کوتاه بازیابی و ترجیحات را می‌توان کنار بلوک قرار داد. اتصال اختیاری وودمارت وجود دارد، اما سازگاری با نسخه‌ها و شخصی‌سازی‌های قالب باید روی همان سایت آزمایش شود. مسیر ورود با رمز عبور را هنگام راه‌اندازی حفظ کنید.

### چگونه از صورتحساب، تولد، معرفی دوستان و بازی‌ها استفاده کنم؟

صورتحساب از حساب کاربری یا پیوند امضاشدهٔ مدت‌دار در دسترس است و می‌توان آن را با مرورگر چاپ یا PDF کرد. `[anar_customer_preferences]` برای ماه و روز تولد، `[anar_referrals]` برای معرفی دوستان و `[anar_game type="wheel"]` برای بازی است. نوع‌های دیگر بازی: scratch، boxes، cards، slots، treasure، daily، quiz، memory و milestone. جوایز کوپن فروشگاه‌اند و نتیجه و سقف مصرف در سرور بررسی می‌شود. تقویم شمسی تولد به PHP Intl نیاز دارد.

## سرویس‌های بیرونی و داده‌های ارسالی

ارسال پیامک به حساب و الگوی تأییدشدهٔ پنل منتخب شما نیاز دارد. هنگام ارسال، شمارهٔ گیرنده، متغیرهای پیام و اطلاعات لازم برای احراز هویت درخواست به همان سرویس‌دهنده فرستاده می‌شود. ورود گوگل نیز فقط با انتخاب مشتری آغاز می‌شود. افزونه اطلاعاتی برای آمارگیری، تبلیغات یا بررسی مجوز به سازندهٔ انار ارسال نمی‌کند. جزئیات فنی و پیوندهای رسمی هر سرویس در ادامه آمده است.


All integrations use accounts configured by the site administrator. The plugin makes no telemetry, licensing or analytics requests to MRNWP or its author. Provider service fees and contracts are independent of the free plugin.

SMS services are contacted when a visitor requests/resends a code, a password sign-in requires a second step, an administrator explicitly sends a test SMS, or an enabled module processes a queued order notification, purchase reminder or customer campaign. Commerce requests can run in the background. No delivery-status polling occurs. The selected provider receives the destination mobile number, the code or message, sender/template identifiers and the provider credentials needed for the request. For commerce, the provider receives configured template values which can include first name, order number, amount, currency, site name, recovery code, code validity, recovery entry-page URL and unsubscribe URL. An administrator notification contains the order variables and the administrator recipient number. The provider also sees the server IP address and standard HTTP metadata. The plugin User-Agent contains its name/version, not the site URL. A configured message may contain the site name or domain.

### Kavenegar

Endpoint: https://api.kavenegar.com/v1/{your-api-key}/verify/lookup.json
Data: API key in the URL path; recipient, OTP and template name in the query string. Purpose and timing are described above.
Official service: https://kavenegar.com/وب-سرویس-پیامک.html
Official API documentation: https://kavenegar.com/rest.html
Official terms and limited account-data-use notice: https://console.kavenegar.com/membership/signup (open the public terms popover beside the consent checkbox; no account or acceptance is required to read it). The notice addresses account-verification documents. A separate privacy policy covering all SMS recipient/message processing has not been verified. This remains an external-documentation review dependency; the service homepage is not represented as a privacy policy.

### Meli Payamak

Endpoints: https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber and https://rest.payamak-panel.com/api/SendSMS/SendSMS
Data: account username/password, recipient and template body ID plus OTP, or sender and configured message for dedicated-number delivery.
Official terms: https://www.melipayamak.com/rules/
A separate public privacy-policy link could not be verified during release preparation. Request the applicable privacy notice from Meli Payamak before using it.

### IPPanel

Endpoint: https://edge.ippanel.com/v1/api/send
Data: API token in the Authorization header; sender number, recipient, pattern code and OTP parameter in a JSON body.
Official service and integration lab: https://ippanel.co/lab/
API infrastructure: https://ippanel.com/
Official API documentation: https://ippanelcom.github.io/Edge-Document/docs/send/pattern
Official terms: https://ippanel.com/login (open the public rules modal using the button beside the login statement; logging in is not needed). The terms describe message monitoring and use of business identifiers. A separate public privacy-policy URL has not been verified. Obtain and review the applicable privacy notice from IPPanel before enabling this integration; the login/rules page is not represented as a standalone privacy policy.

### Panelchi

Endpoint: https://api.panelchi.com/sms/pattern (HTTPS POST).
Data: API token in the Authorization: Bearer header; recipient mobile number, approved pattern slug, sourceNumber (10001 by default) and pattern variables in a JSON body. OTP requests send the code variable. Enabled commerce modules send their separately configured template variables, including recovery code, recovery entry page and unsubscribe link when applicable. Requests occur only at the SMS delivery times described above. The provider also receives the server IP and standard HTTP metadata. A request UID and an accepted/queued status indicate API acceptance, not handset delivery.
Official service/account portal: https://panelchi.com/
Official API documentation: https://docs.panelchi.com/redoc/
Account activation, identity verification and an active approved pattern may be required by the provider. Access from servers outside Iran may be restricted. The plugin does not create accounts or patterns, complete identity verification, or poll delivery status automatically.
Separate publicly accessible terms and privacy-policy URLs could not be verified. Obtain and review the applicable terms and data-processing/privacy notice from Panelchi before enabling this integration; the account portal and API documentation are not represented as privacy policies.

### MaxSMS

Endpoint: https://api.panelchi.com/sms/pattern (HTTPS POST).
MaxSMS uses the Panelchi-compatible pattern API documented on its official webservice page. The provider receives the recipient, approved pattern slug, pattern variables, source number and Bearer API token at the delivery times described above. MaxSMS settings are stored separately from Panelchi settings; selecting MaxSMS does not reuse a Panelchi account token or pattern automatically. Login and commerce patterns remain separate.
Official service: https://maxsms.co/
Official integration documentation: https://maxsms.co/webservice/
Shared API documentation: https://docs.panelchi.com/redoc/
Account activation and approved patterns are managed by the provider. Review the applicable MaxSMS service terms and privacy notice, including processing by the shared Panelchi infrastructure, before enabling delivery. A separate public privacy-policy URL has not been verified; the service and API documentation are not represented as privacy policies.

### Faraz SMS

Endpoint: https://ippanel.com/patterns/pattern
Data: Faraz account username/password, sender, recipient, pattern code and OTP parameter as form fields. Delivery uses IPPanel infrastructure; consult your Faraz service contract for processing by that infrastructure.
Official terms and privacy commitment (panel rules, clause 12): https://farazsms.com/terms-conditions/
Official API documentation: https://docs.farazsms.com/

### SMS.ir

Endpoint: https://api.sms.ir/v1/send/verify
Data: API key in the X-API-KEY header; recipient, numeric template ID and OTP parameter in JSON.
Official terms, including its security/privacy commitment: https://sms.ir/terms-and-conditions/

### Custom gateway

The administrator supplies the HTTPS endpoint, authentication headers, parameters and response-success text. Only this configured service receives the recipient/code/message/sender and any credentials placed in the templates. The plugin cannot know the operator of an arbitrary URL. Review that operator's terms and privacy notice before saving the endpoint. GET requests may expose codes or credentials in provider/proxy URL logs; prefer POST when the service supports it. Redirects are not followed and WordPress safe HTTP validation remains enabled.

### Google sign-in

Google is contacted only when a visitor chooses Google sign-in and completes the OAuth callback. The browser visits https://accounts.google.com/o/oauth2/v2/auth with the client ID, redirect URI, openid/email/profile scopes, state and PKCE challenge. The server exchanges the authorization code and PKCE verifier at https://oauth2.googleapis.com/token using the configured client secret, then requests https://openidconnect.googleapis.com/v1/userinfo with the temporary access token. Google returns its verified account identifier, email, name and optional profile image. These identify or create the local WordPress account; the plugin does not retain Google access or refresh tokens. A Google-hosted profile image can be loaded in the account panel.
Terms: https://policies.google.com/terms
Privacy: https://policies.google.com/privacy

### Email and profile images

Email codes use WordPress wp_mail; delivery depends on the site's hosting, SMTP plugin or configured mail service, which receives the address and code message. This plugin does not bundle an external email transport. WordPress avatar functions can use Gravatar for profile images, depending on site configuration; its image requests include the visitor IP address and an email-derived avatar identifier.
Gravatar terms: https://wordpress.com/tos/
Gravatar privacy: https://automattic.com/privacy/

## حریم خصوصی و نگهداری اطلاعات

مشخصات حساب در رکوردهای معمول کاربران وردپرس و شمارهٔ تأییدشده، شناسهٔ حساب گوگل و نشانی اختیاری تصویر در فرادادهٔ کاربر ذخیره می‌شوند. اطلاعات اتصال پنل‌ها در پایگاه دادهٔ سایت نگهداری می‌شود؛ افزونه ادعای رمزگذاری آن‌ها را ندارد. از دسترسی مدیر و نسخه‌های پشتیبان حفاظت کنید.

سبدها شامل کالا، متغیر، تعداد، مبلغ، ارز، شناسهٔ کلیدخوردهٔ نشست و سفارش مرتبط هستند. شماره، ترجیح دریافت پیام و اطلاعات لازم برای کمپین در جدول‌های محلی ذخیره می‌شوند. کدهای تأیید به صورت هش کلیدخورده با محدودیت تلاش و انقضا نگهداری می‌شوند؛ کد خام، متن کامل پیام و اطلاعات محرمانهٔ پنل در گزارش صف ثبت نمی‌شوند. گزارش اختیاری ورود، نتیجه و زمان درخواست را نگه می‌دارد.

زمان نگهداری اطلاعات سبد و پیام ۷ تا ۹۰ روز و فعالیت بازاریابی ۷ تا ۳۶۵ روز قابل تنظیم است. پاک‌سازی روزانه به اجرای زمان‌بند وابسته است. خروجی و حذف اطلاعات از ابزارهای حریم خصوصی وردپرس و حذف بر اساس شماره توسط مدیر پس از احراز هویت درخواست‌کننده در دسترس است. حداقل هش لازم برای حفظ لغو پیام پس از حذف هویت باقی می‌ماند. سفارش ووکامرس و داده‌های نگهداری‌شده در شرکت پیامکی جدا هستند.

آمار بازدید اختیاری و محلی است: شناسهٔ تصادفی مرورگر تا ۳۰ روز، هش روزانه و نام میزبان ارجاع‌دهنده استفاده می‌شود؛ نشانی IP خام یا نشانی کامل ارجاع ذخیره نمی‌شود. داده به سرویس آمارگیری بیرونی ارسال نمی‌شود. نام ماه و روز تولد بدون سال ذخیره می‌شود. ورود و سبد از سازوکارهای کوکی و نشست وردپرس و ووکامرس استفاده می‌کنند.

حذف افزونه به‌طور پیش‌فرض اطلاعات را نگه می‌دارد. گزینهٔ حذف داده باید پیش از پاک‌کردن افزونه فعال شده باشد؛ کاربران وردپرس و سفارش‌های ووکامرس حذف نمی‌شوند. نصب و حذف شبکه‌ای میان چند سایت پیاده‌سازی نشده است؛ هر سایت را جدا تنظیم کنید. شرح فنی سرویس‌ها در services.txt و راهنمای فارسی همراه افزونه آمده است.

## تصاویر

1. تنظیمات انار و اتصال سرویس‌ها در پیشخوان فارسی.
2. فرم ورود راست‌به‌چپ با نشان و هویت سایت.
3. تنظیمات پنل پیامکی و آزمایش ارسال توسط مدیر.
4. فرم ورود انگلیسی با چینش چپ‌به‌راست.

## تغییرات

### 0.8.1
* افزودن ترجمهٔ آمادهٔ فارسی به بستهٔ نصب و بارگذاری آن با زبان سایت یا کاربر؛ بدون نیاز به بستهٔ جداگانه.
* بازنویسی معرفی، راه‌اندازی و پرسش‌های متداول برای مخاطب فارسی و معرفی امکانات جدید.
* حفظ اولویت ترجمه‌های رسمی و سفارشی وردپرس و افزودن بررسی نصب بدون بستهٔ زبان بیرونی.

### 0.8.0
* افزودن بازیابی رمز پیامکی، ثبت خودکار سبد، تخفیف اختصاصی و اعلان سفارش و مرسوله.
* افزودن ابزارهای چرخهٔ مشتری، پیش‌بینی خرید، پیشنهاد کالا، ارسال گروهی، موجودی، نظرسنجی، تولد، صورتحساب، بازی و معرفی دوستان.
* افزودن مدیریت مستقل قابلیت‌ها، گزارش‌های واقعی و راهنماهای فارسی؛ قابلیت‌های غیرفعال پایین‌تر باقی می‌مانند.

سوابق کامل تغییرات: https://github.com/mehran-mrn/anar-login/blob/main/CHANGELOG.md

## یادداشت به‌روزرسانی

### 0.8.1
ترجمهٔ فارسی اکنون همراه افزونه نصب می‌شود. زبان سایت یا کاربر را فارسی قرار دهید؛ نصب دستی بستهٔ زبان لازم نیست.
