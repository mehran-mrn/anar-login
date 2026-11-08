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
		add_action( 'init', array( GameCampaign::class, 'migrate' ), 20 );
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
			'button_label' => array( 'wheel' => __( 'Spin the wheel', 'anar-toolkit' ), 'slots' => __( 'Start the reels', 'anar-toolkit' ), 'daily' => __( 'Receive today’s gift', 'anar-toolkit' ), 'milestone' => __( 'Claim my milestone gift', 'anar-toolkit' ) )[ $type ] ?? __( 'Reveal my result', 'anar-toolkit' ),
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
		wp_enqueue_style( 'anar-games', ANAR_LOGIN_URL . 'assets/css/games.css', array( 'anar-marketing' ), ANAR_LOGIN_VERSION );
		$config = wp_parse_args( $config, GameCampaign::defaults() );
		$profile_id = (int) ( new Repository() )->remember( PhoneVerification::phone( get_current_user_id() ) );
		$remaining = $this->remaining( $profile_id, $type, $config );
		$initial_state = 'ready';
		$initial_message = '';
		if ( $remaining < 1 ) {
			$initial_state = 'quota';
			$initial_message = __( 'Your daily play limit has been reached. Come back tomorrow.', 'anar-toolkit' );
		} elseif ( ! GameCampaign::active( $config ) ) {
			$initial_state = 'unavailable';
			$initial_message = __( 'This promotion is not currently available.', 'anar-toolkit' );
		} elseif ( 'stop' === $config['game_pool_empty'] && ! GameCampaign::availability( $config, $type, $profile_id )['prizes'] ) {
			$initial_state = 'exhausted';
			$initial_message = __( 'All gifts in this promotion have been claimed.', 'anar-toolkit' );
		}
		ob_start();
		?>
		<section class="anar-game anar-skin-<?php echo esc_attr( $config['game_skin'] ); ?>" data-anar-game data-state="<?php echo esc_attr( $initial_state ); ?>" data-type="<?php echo esc_attr( $type ); ?>" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'anar_game' ) ); ?>" data-account="<?php echo (int) get_current_user_id(); ?>" data-remaining="<?php echo (int) $remaining; ?>" data-quota-message="<?php esc_attr_e( 'Your daily play limit has been reached. Come back tomorrow.', 'anar-toolkit' ); ?>" data-network="<?php esc_attr_e( 'Connection interrupted. Retry to continue the same game.', 'anar-toolkit' ); ?>" data-wait="<?php esc_attr_e( 'Preparing your result…', 'anar-toolkit' ); ?>" data-completed="<?php esc_attr_e( 'Attempt completed', 'anar-toolkit' ); ?>" data-left="<?php /* translators: %d: remaining daily attempts. */ esc_attr_e( '%d attempts left today', 'anar-toolkit' ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'anar-toolkit' ); ?>" style="<?php echo $config['game_color'] ? esc_attr( '--game-accent:' . $config['game_color'] ) : ''; ?>">
			<header class="anar-game-header"><span class="anar-game-kicker"><?php esc_html_e( 'A gift from your store', 'anar-toolkit' ); ?></span><span class="anar-game-quota"><?php echo esc_html( sprintf( /* translators: %d: remaining daily attempts. */ __( '%d attempts left today', 'anar-toolkit' ), $remaining ) ); ?></span><h2><?php echo esc_html( $texts['title'] ); ?></h2><p><?php echo esc_html( $texts['intro'] ); ?></p></header>
			<?php GameView::render( $type, $config, $texts, $profile_id ); ?>
			<p class="anar-game-result" role="status" aria-live="polite" data-status><?php echo esc_html( $initial_message ); ?></p><div class="anar-game-coupon" data-coupon hidden><span data-prize-label><?php echo esc_html( $texts['reward_label'] ); ?></span><div><strong dir="ltr"></strong><button type="button" data-copy><?php esc_html_e( 'Copy code', 'anar-toolkit' ); ?></button></div><small data-prize-terms></small></div><button type="button" class="anar-game-secondary" data-retry hidden><?php esc_html_e( 'Continue this game', 'anar-toolkit' ); ?></button>
			<footer class="anar-game-footer"><?php esc_html_e( 'Free to play. Coupons are personal and have no cash value.', 'anar-toolkit' ); ?></footer>
		</section>
		<?php
		return ob_get_clean();
	}

	public function remaining( $profile_id, $game, $config ) {
		global $wpdb;
		$table = ( new Repository() )->table( 'plays' );
		$midnight = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE profile_id = %d AND created_at >= %d', $table, $profile_id, $midnight ) );
		if ( null === $total ) {
			return 0;
		}
		$mode = $config['game_quota_mode'];
		if ( 'shared' === $mode ) {
			return max( 0, (int) $config['games_limit'] - $total );
		}
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE profile_id = %d AND game = %s AND created_at >= %d', $table, $profile_id, $game, $midnight ) );
		if ( null === $count ) {
			return 0;
		}
		$left = max( 0, (int) ( $config['game_limits'][ $game ] ?? $config['games_limit'] ) - $count );
		return 'combined' === $mode ? min( $left, max( 0, (int) $config['games_total_limit'] - $total ) ) : $left;
	}

	public function play( $profile, $game, $play_id = 0, $choice = 0, $request = '', $move = '' ) {
		if ( ! $profile ) {
			return new \WP_Error( 'anar_game_unavailable', __( 'This game is unavailable.', 'anar-toolkit' ) );
		}
		if ( ! GameCampaign::migrate() ) {
			return new \WP_Error( 'anar_game_upgrade', __( 'Game records are being updated. Please try again shortly.', 'anar-toolkit' ) );
		}
		GameBudget::sweep( false );
		$lock = GameCampaign::lock( 'profile:' . $profile->id );
		if ( ! $lock ) {
			return new \WP_Error( 'anar_game_busy', __( 'Your game is being processed. Please try again.', 'anar-toolkit' ) );
		}
		try {
			return $this->play_locked( $profile, $game, $play_id, $choice, $request, $move );
		} finally {
			GameCampaign::unlock( $lock );
		}
	}

	private function play_locked( $profile, $game, $play_id, $choice, $request, $move ) {
		global $wpdb;
		$config = ( new Settings() )->all();
		$repo = new Repository();
		if ( ! ( new Settings() )->enabled( 'games' ) || ! isset( self::types()[ $game ] ) || ! $profile ) {
			return new \WP_Error( 'anar_game_unavailable', __( 'This game is unavailable.', 'anar-toolkit' ) ); }
		$texts = self::texts( $game, $config );
		$table = $repo->table( 'plays' );
		$play = $play_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND profile_id = %d AND game = %s', $table, $play_id, $profile->id, $game ) ) : null;
		$request_key = $request ? hash( 'sha256', $profile->id . '|' . $game . '|' . $request ) : '';
		if ( ! $play && $play_id ) {
			return new \WP_Error( 'anar_game_missing', __( 'This game could not be found. Reload the page.', 'anar-toolkit' ) );
		}
		if ( ! $play && $request_key ) {
			$play = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE request_key = %s AND profile_id = %d AND game = %s', $table, $request_key, $profile->id, $game ) );
		}
		if ( ! $play ) {
			if ( ! wc_coupons_enabled() ) {
				return new \WP_Error( 'anar_game_unavailable', __( 'This game is unavailable.', 'anar-toolkit' ) );
			}
			if ( ! GameCampaign::active( $config ) ) {
				return new \WP_Error( 'anar_game_campaign', __( 'This promotion is not currently available.', 'anar-toolkit' ) );
			}
			if ( $this->remaining( $profile->id, $game, $config ) < 1 ) {
				return new \WP_Error( 'anar_game_quota', __( 'Your daily play limit has been reached. Come back tomorrow.', 'anar-toolkit' ) );
			}
			$available = GameCampaign::availability( $config, $game, $profile->id );
			if ( ! $available['prizes'] && 'stop' === $config['game_pool_empty'] ) {
				return new \WP_Error( 'anar_game_empty', __( 'All gifts in this promotion have been claimed.', 'anar-toolkit' ) );
			}
			$bucket = wp_date( 'Y-m-d' ) . ':' . wp_generate_uuid4();
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
			$symbols = array( 'cherry', 'citrus', 'grape', 'berry', 'cherry', 'citrus', 'grape', 'berry' );
			for ( $i = count( $symbols ) - 1; $i > 0; --$i ) {
				$j = random_int( 0, $i );
				$temp = $symbols[ $i ];
				$symbols[ $i ] = $symbols[ $j ];
				$symbols[ $j ] = $temp; }
			$payload['board'] = $symbols;
			$key = 'milestone' === $game ? hash( 'sha256', $profile->id . '|' . $bucket ) : ( $request_key ?: hash( 'sha256', $profile->id . '|' . $game . '|' . $bucket ) );
			$insert = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (play_key, request_key, profile_id, game, payload, created_at) VALUES (%s,%s,%d,%s,%s,%d)', $table, $key, $request_key ?: $key, $profile->id, $game, wp_json_encode( $payload ), time() ) );
			if ( 1 !== $insert ) {
				return new \WP_Error( 'anar_game_quota', __( 'This play has already been reserved. Reload before starting another.', 'anar-toolkit' ) ); }
			$play = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE play_key = %s', $table, $key ) );
		}
		if ( 'started' === $play->state && $play->created_at < time() - 30 * MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'anar_game_expired', __( 'This game expired. Try again when your next play is available.', 'anar-toolkit' ) ); }
		$payload = json_decode( $play->payload, true );
		if ( isset( $payload['result'] ) && ( 'finished' === $play->state || ( $move && ( $payload['move_id'] ?? '' ) === $move ) ) ) {
			return $payload['result'];
		}
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
		$award = array( 'coupon' => '', 'prize_id' => '', 'prize_label' => '', 'exhausted' => false );
		if ( $finished && $payload['won'] ) {
			$award = GameCampaign::award( $profile, $game, $play->id, $config );
			if ( is_wp_error( $award ) ) {
				return $award;
			}
			if ( $award['coupon'] ) {
				$wpdb->update( $table, array( 'coupon_id' => wc_get_coupon_id_by_code( $award['coupon'] ) ), array( 'id' => $play->id ) );
			}
		}
		if ( 'wheel' === $game ) {
			$award['wheel_segments'] = GameView::segments( $config, $game, $texts, $profile->id );
			if ( $award['coupon'] && ! in_array( $award['prize_id'], array_column( $award['wheel_segments'], 'id' ), true ) ) {
				$award['wheel_segments'][0] = array( 'id' => $award['prize_id'], 'label' => $award['prize_label'] ?: $texts['reward_label'] );
			}
		}
		$total_config = $config;
		$total_config['game_quota_mode'] = 'shared';
		$total_config['games_limit'] = 'combined' === $config['game_quota_mode'] ? $config['games_total_limit'] : $config['games_limit'];
		$result = array_merge( $award, array( 'play_id' => (int) $play->id, 'finished' => $finished, 'won' => $finished && (bool) $award['coupon'], 'revealed' => (object) $revealed, 'matched' => $payload['matched'], 'moves' => $payload['moves'], 'quota_mode' => $config['game_quota_mode'], 'total_remaining' => $this->remaining( $profile->id, $game, $total_config ), 'remaining' => $this->remaining( $profile->id, $game, $config ), 'message' => $finished ? ( $award['coupon'] ? $texts['win_message'] : ( $award['exhausted'] ? __( 'All gifts in this promotion have been claimed.', 'anar-toolkit' ) : $texts['lose_message'] ) ) : __( 'Match the cards to complete your game.', 'anar-toolkit' ) ) );
		$payload['result'] = $result;
		$payload['move_id'] = $move;
		if ( false === $wpdb->update( $table, array( 'payload' => wp_json_encode( $payload ) ), array( 'id' => $play->id ) ) ) {
			return new \WP_Error( 'anar_game_save', __( 'Your game is being processed. Please try again.', 'anar-toolkit' ) );
		}
		return $result;
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
		$request = isset( $_POST['request_id'] ) && is_string( $_POST['request_id'] ) ? substr( sanitize_key( wp_unslash( $_POST['request_id'] ) ), 0, 64 ) : '';
		$move = isset( $_POST['move_id'] ) && is_string( $_POST['move_id'] ) ? substr( sanitize_key( wp_unslash( $_POST['move_id'] ) ), 0, 64 ) : '';
		$result = $this->play( $repo->profile( $id ), $game, isset( $_POST['play_id'] ) ? absint( $_POST['play_id'] ) : 0, isset( $_POST['choice'] ) ? (int) $_POST['choice'] : 0, $request, $move );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ), 400 ); }
		wp_send_json_success( $result );
	}
}
