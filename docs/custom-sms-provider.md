# افزودن درگاه پیامکی

هر درگاه باید `SmsProviderInterface` را پیاده‌سازی کند:

```php
namespace Vendor\Plugin;

use Anar\Login\Messaging\SmsProviderInterface;
use WP_Error;

final class MyProvider implements SmsProviderInterface {
	private $api_key;
	private $template_id;

	public function __construct( $api_key, $template_id ) {
		$this->api_key    = $api_key;
		$this->template_id = $template_id;
	}

	public function key() {
		return 'my-provider';
	}

	public function title() {
		return 'درگاه من';
	}

	public function fields() {
		return array(
			'api_key'     => 'API Key',
			'template_id' => 'Template ID',
		);
	}

	public function send( $recipient, $code, $message ) {
		$response = wp_safe_remote_post(
			'https://api.example.com/v1/verify',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'to'       => $recipient,
						'template' => $this->template_id,
						'code'     => $code,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'my_provider_network', 'ارتباط با درگاه برقرار نشد.' );
		}

		return 202 === wp_remote_retrieve_response_code( $response )
			? true
			: new WP_Error( 'my_provider_rejected', 'درگاه پیام را نپذیرفت.' );
	}
}
```

سپس instance را ثبت کنید:

```php
add_filter(
	'anar_login_sms_providers',
	function ( $providers, $settings ) {
		$config = get_option( 'my_plugin_sms', array() );
		$providers['my-provider'] = new \Vendor\Plugin\MyProvider(
			$config['api_key'] ?? '',
			$config['template_id'] ?? ''
		);
		return $providers;
	},
	10,
	2
);
```

## قواعد قرارداد

- `send()` فقط در موفقیت قطعی `true` برگرداند.
- هر شکست باید `WP_Error` با code پایدار برگرداند.
- OTP، API key، password و پاسخ حاوی راز را log نکنید.
- TLS verification را غیرفعال نکنید.
- timeout محدود و endpoint ثابت HTTPS داشته باشید.
- canonical phone ورودی `+989xxxxxxxxx` است؛ تبدیل به `09...` فقط وقتی API الزام دارد انجام شود.
- متن `$message` برای gateway متنی آماده است؛ gateway الگویی از `$code` استفاده می‌کند.
- provider نباید کاربر بسازد، کوکی ورود تنظیم کند یا challenge را ذخیره کند.

از نسخهٔ 0.3.0، فیلدهای `fields()` هر provider ثبت‌شده در تب پیامک نمایش داده و در `anar_login_settings['provider_{key}']` ذخیره می‌شوند. اگر provider از `AbstractProvider` ارث ببرد، `$this->config()` همین مقادیر را برمی‌گرداند و دیگر نیازی به option جداگانه نیست. فیلدهای `api_key`، `password`، `token` و `secret` محرمانه‌اند و پس از ذخیره نمایش داده نمی‌شوند؛ فهرست را با فیلتر `anar_login_secret_provider_fields` گسترش دهید. ابزار «ارسال پیامک آزمایشی» در همان تب، provider سفارشی را هم آزمایش می‌کند.

## پیام‌های فروشگاه و بازاریابی

ورود، سفارش‌ها و کمپین‌ها همه از یک مسیر ارسال استفاده می‌کنند. درگاهی که از `AbstractProvider` ارث ببرد و `send_template( $recipient, $template, $params )` را پیاده کند، برای همهٔ پیام‌های الگویی قابل انتخاب است. `send()` کد ورود را خودکار با همین متد و مقادیر `template_id` و `code_parameter` تنظیمات ارسال می‌کند. اگر پنل ارسال متنی هم دارد، `send_text()` و `text_credentials()` را نیز پیاده کنید. فیلدهای لازم را در `template_credentials()` برگردانید تا ویرایشگر پیام پیش از ارسال، تنظیمات ناقص را نشان دهد. درگاهی که فقط `SmsProviderInterface` را پیاده کند، همچنان برای کد ورود کار می‌کند.
