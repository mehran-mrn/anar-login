<?php
/**
 * Free promotional games; quotas, outcomes and rewards are enforced server-side.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class Games {
	public static function types() {
		return array( 'wheel' => __( 'Lucky wheel', 'anar-toolkit' ), 'scratch' => __( 'Scratch card', 'anar-toolkit' ), 'boxes' => __( 'Pick a gift box', 'anar-toolkit' ), 'cards' => __( 'Turn a lucky card', 'anar-toolkit' ), 'slots' => __( 'Fruit match', 'anar-toolkit' ), 'treasure' => __( 'Treasure hunt', 'anar-toolkit' ), 'daily' => __( 'Daily gift', 'anar-toolkit' ), 'quiz' => __( 'Store quiz', 'anar-toolkit' ), 'memory' => __( 'Memory match', 'anar-toolkit' ), 'milestone' => __( 'Purchase milestones', 'anar-toolkit' ) );
	}

	public function register() {
		add_shortcode( 'anar_games', array( $this, 'form' ) );
		add_shortcode( 'anar_game', array( $this, 'form' ) );
		add_action( 'wp_ajax_anar_game', array( $this, 'action' ) );
		add_action( 'wp_ajax_anar_game_phone', array( new PhoneVerification(), 'action' ) );
	}

	public static function texts( $type, $config ) {
		$defaults = array(
			'title' => self::types()[ $type ],
			'intro' => __( 'Play for a chance to receive a personal store coupon.', 'anar-toolkit' ),
			'reward_label' => __( 'Your personal coupon', 'anar-toolkit' ),
			'win_message' => __( 'Your coupon is ready. Use it with your verified billing phone.', 'anar-toolkit' ),
			'lose_message' => __( 'No coupon was issued for this attempt.', 'anar-toolkit' ),
			'button_label' => __( 'Reveal my result', 'anar-toolkit' ),
			'wheel_miss_label' => __( 'Next time', 'anar-toolkit' ),
		);
		$saved = $config['game_texts'][ $type ] ?? array();
		foreach ( $defaults as $key => &$text ) {
			if ( ! empty( $saved[ $key ] ) && is_string( $saved[ $key ] ) ) {
				$text = $saved[ $key ];
			}
		}
		unset( $text );
		return $defaults;
	}

	public static function quiz_options( $config ) {
		return array_slice( array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', $config['game_quiz_options'] ) ), 'strlen' ) ), 0, 8 );
	}

	public function form( $atts = array() ) {
		if ( ! ( new Settings() )->enabled( 'games' ) ) {
			return ''; }
		$atts = shortcode_atts( array( 'type' => 'wheel' ), $atts, 'anar_game' );
		$type = isset( self::types()[ $atts['type'] ] ) ? $atts['type'] : 'wheel';
		if ( ! is_user_logged_in() ) {
			return '<div class="anar-game-signin"><p>' . esc_html__( 'Sign in with your verified mobile number to play and receive a personal gift.', 'anar-toolkit' ) . '</p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in to play', 'anar-toolkit' ) . '</a></div>'; }
		if ( ! PhoneVerification::phone( get_current_user_id() ) ) {
			return PhoneVerification::form();
		}
		wp_enqueue_script( 'anar-games', ANAR_LOGIN_URL . 'assets/js/games.js', array(), ANAR_LOGIN_VERSION, true );
		wp_enqueue_style( 'anar-marketing', ANAR_LOGIN_URL . 'assets/css/marketing.css', array(), ANAR_LOGIN_VERSION );
		$config = ( new Settings() )->all();
		$texts = self::texts( $type, $config );
		ob_start();
		?>
		<section class="anar-game" data-anar-game data-type="<?php echo esc_attr( $type ); ?>" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'anar_game' ) ); ?>" data-network="<?php esc_attr_e( 'Could not connect. Please try again.', 'anar-toolkit' ); ?>">
				<span class="anar-game-kicker"><?php esc_html_e( 'Store promotion', 'anar-toolkit' ); ?></span><h2><?php echo esc_html( $texts['title'] ); ?></h2><p><?php echo esc_html( $texts['intro'] ); ?></p><p><?php esc_html_e( 'A free store promotion. No payment or purchase stake is required. Coupons have no cash value and are limited to your verified phone.', 'anar-toolkit' ); ?></p>
			<?php
			if ( 'wheel' === $type ) :
				?>
					<div class="anar-wheel-frame"><span class="anar-wheel-pointer" aria-hidden="true">▼</span><div class="anar-wheel" data-wheel><strong class="anar-wheel-prize"><?php echo esc_html( $texts['reward_label'] ); ?></strong><strong class="anar-wheel-miss"><?php echo esc_html( $texts['wheel_miss_label'] ); ?></strong><span>✦</span></div></div>
				<?php
elseif ( 'slots' === $type ) :
	?>
				<div class="anar-slots" data-slots><span>🍒</span><span>🍋</span><span>🍇</span></div>
				<?php
elseif ( 'scratch' === $type ) :
	?>
	<div class="anar-scratch" tabindex="0" role="button" data-pick="0" aria-label="<?php esc_attr_e( 'Reveal your surprise', 'anar-toolkit' ); ?>"><span>✦</span><strong><?php esc_html_e( 'Scratch or tap to reveal', 'anar-toolkit' ); ?></strong><canvas width="600" height="240" aria-hidden="true"></canvas></div>
	<?php
elseif ( 'quiz' === $type ) :
	?>
	<h3><?php echo esc_html( $config['game_quiz_question'] ); ?></h3><div class="anar-game-options">
	<?php
	foreach ( self::quiz_options( $config ) as $index => $option ) :
		if ( ! trim( $option ) ) {
			continue; }
		?>
	<button type="button" data-pick="<?php echo esc_attr( $index + 1 ); ?>"><?php echo esc_html( $option ); ?></button><?php endforeach; ?></div>
	<?php
elseif ( in_array( $type, array( 'boxes', 'cards', 'treasure', 'memory' ), true ) ) :
	?>
	<div class="anar-game-grid <?php echo 'cards' === $type ? 'anar-game-cards' : ''; ?>">
	<?php
	$count = 'memory' === $type ? 8 : ( 'treasure' === $type ? 9 : 3 ); for ( $index = 0; $index < $count; ++$index ) :
		?>
	<button type="button" data-pick="<?php echo esc_attr( $index ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: item number. */ __( 'Choose item %d', 'anar-toolkit' ), $index + 1 ) ); ?>"><?php echo 'boxes' === $type ? '🎁' : '✦'; ?></button><?php endfor; ?></div>
	<?php
else :
	?>
	<div class="anar-game-emblem"><?php echo 'daily' === $type ? '☀' : '✦'; ?></div><?php endif; ?>
			<?php
			if ( ! in_array( $type, array( 'boxes', 'cards', 'treasure', 'quiz', 'memory', 'scratch' ), true ) ) :
				?>
					<button type="button" class="anar-game-start" data-pick="0"><?php echo esc_html( $texts['button_label'] ); ?></button><?php endif; ?>
				<p class="anar-game-result" role="status" aria-live="polite" data-status></p><div class="anar-game-coupon" data-coupon hidden><span><?php echo esc_html( $texts['reward_label'] ); ?></span><strong dir="ltr"></strong></div>
		</section>
		<?php
		return ob_get_clean();
	}

	public function play( $profile, $game, $play_id = 0, $choice = 0 ) {
		global $wpdb;
		$config = ( new Settings() )->all();
		$repo = new Repository();
		if ( ! ( new Settings() )->enabled( 'games' ) || ! isset( self::types()[ $game ] ) || ! $profile ) {
			return new \WP_Error( 'anar_game_unavailable', __( 'This game is unavailable.', 'anar-toolkit' ) ); }
		$texts = self::texts( $game, $config );
		$table = $repo->table( 'plays' );
		$play = $play_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND profile_id = %d AND game = %s', $table, $play_id, $profile->id, $game ) ) : null;
		if ( ! $play ) {
			$midnight = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE profile_id = %d AND created_at >= %d', $table, $profile->id, $midnight ) );
			if ( $count >= $config['games_limit'] ) {
				return new \WP_Error( 'anar_game_quota', __( 'Your daily play limit has been reached. Come back tomorrow.', 'anar-toolkit' ) ); }
			$bucket = wp_date( 'Y-m-d' ) . ':' . $count;
			if ( 'milestone' === $game ) {
				$facts = $repo->facts( $profile->id );
				$milestone = (int) floor( $facts->orders / $config['game_milestone_orders'] );
				if ( $milestone < 1 ) {
					return new \WP_Error( 'anar_milestone_pending', __( 'Complete the configured purchase milestone before claiming this gift.', 'anar-toolkit' ) ); }
				$bucket = 'milestone:' . $milestone;
			}
			$payload = array( 'won' => 'milestone' === $game || random_int( 1, 100 ) <= $config['game_reward_chance'], 'moves' => 0, 'matched' => array(), 'open' => array(), 'answer' => $config['game_quiz_answer'] );
			if ( 'quiz' === $game && ( ! $config['game_quiz_question'] || count( self::quiz_options( $config ) ) < 2 || $config['game_quiz_answer'] > count( self::quiz_options( $config ) ) ) ) {
				return new \WP_Error( 'anar_quiz_unconfigured', __( 'The store quiz is not configured yet.', 'anar-toolkit' ) ); }
			$symbols = array( '🍒', '🍋', '🍇', '🍓', '🍒', '🍋', '🍇', '🍓' );
			for ( $i = count( $symbols ) - 1; $i > 0; --$i ) {
				$j = random_int( 0, $i );
				$temp = $symbols[ $i ];
				$symbols[ $i ] = $symbols[ $j ];
				$symbols[ $j ] = $temp; }
			$payload['board'] = $symbols;
			$key = hash( 'sha256', $profile->id . '|' . $bucket );
			$insert = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (play_key, profile_id, game, payload, created_at) VALUES (%s,%d,%s,%s,%d)', $table, $key, $profile->id, $game, wp_json_encode( $payload ), time() ) );
			if ( 1 !== $insert ) {
				return new \WP_Error( 'anar_game_quota', __( 'This play has already been reserved. Reload before starting another.', 'anar-toolkit' ) ); }
			$play = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE play_key = %s', $table, $key ) );
		}
		if ( $play->created_at < time() - 30 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'anar_game_expired', __( 'This game expired. Try again when your next play is available.', 'anar-toolkit' ) ); }
		$payload = json_decode( $play->payload, true );
		$revealed = array();
		$finished = 'memory' !== $game;
		if ( 'started' === $play->state && 'memory' === $game ) {
			if ( $choice < 0 || $choice > 7 || in_array( $choice, $payload['matched'], true ) || in_array( $choice, $payload['open'], true ) ) {
				return new \WP_Error( 'anar_game_move', __( 'Choose an unmatched card.', 'anar-toolkit' ) ); }
			if ( count( $payload['open'] ) >= 2 ) {
				$payload['open'] = array(); }
			$payload['open'][] = $choice;
			++$payload['moves'];
			foreach ( $payload['open'] as $index ) {
				$revealed[ $index ] = $payload['board'][ $index ]; }
			if ( 2 === count( $payload['open'] ) && $payload['board'][ $payload['open'][0] ] === $payload['board'][ $payload['open'][1] ] ) {
				$payload['matched'] = array_merge( $payload['matched'], $payload['open'] );
				$payload['open'] = array(); }
			$finished = count( $payload['matched'] ) === 8 || $payload['moves'] >= 24;
			if ( $payload['moves'] >= 24 && count( $payload['matched'] ) < 8 ) {
				$payload['won'] = false; }
		}
		if ( 'quiz' === $game && $choice !== (int) $payload['answer'] ) {
			$payload['won'] = false; }
		if ( 'started' === $play->state ) {
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET payload = %s, state = %s WHERE id = %d AND state = %s AND payload = %s', $table, wp_json_encode( $payload ), $finished ? 'finished' : 'started', $play->id, 'started', $play->payload ) );
			if ( 1 !== $ok ) {
				return new \WP_Error( 'anar_game_concurrent', __( 'Another game action is in progress. Reload and try again.', 'anar-toolkit' ) ); }
		} else {
			$finished = true; }
		$code = '';
		if ( $finished && $payload['won'] ) {
			$rule = array( 'discount_type' => $config['game_reward_type'], 'discount_amount' => $config['game_reward_amount'], 'discount_hours' => 48 );
			$code = ( new Rewards() )->coupon( $profile, $rule, 'game:' . $play->id );
			if ( $code ) {
				$wpdb->update( $table, array( 'coupon_id' => wc_get_coupon_id_by_code( $code ) ), array( 'id' => $play->id ) ); }
		}
		return array( 'play_id' => (int) $play->id, 'finished' => $finished, 'won' => $finished && (bool) $code, 'coupon' => $code, 'revealed' => (object) $revealed, 'matched' => $payload['matched'], 'message' => $finished ? ( $code ? $texts['win_message'] : $texts['lose_message'] ) : __( 'Match the cards to complete your game.', 'anar-toolkit' ) );
	}

	public function action() {
		check_ajax_referer( 'anar_game', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array(), 403 ); }
		$phone = PhoneVerification::phone( get_current_user_id() );
		if ( ! $phone ) {
			wp_send_json_error( array( 'message' => __( 'Verify your mobile number before playing.', 'anar-toolkit' ) ), 403 );
		}
		$repo = new Repository();
		$id = $repo->remember( $phone, wp_get_current_user()->user_email, wp_get_current_user()->first_name, get_current_user_id() );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above by check_ajax_referer.
		$game = isset( $_POST['game'] ) ? sanitize_key( wp_unslash( $_POST['game'] ) ) : '';
		$result = $this->play( $repo->profile( $id ), $game, isset( $_POST['play_id'] ) ? absint( $_POST['play_id'] ) : 0, isset( $_POST['choice'] ) ? (int) $_POST['choice'] : 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( $result );
	}
}
