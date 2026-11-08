<?php
/**
 * Accessible, themeable presentation for every promotional game.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class GameView {

	public static function icon( $name ) {
		$paths = array(
			'gift' => '<path d="M8 29h48v28H8zM5 20h54v10H5zM32 20v37M32 20C7 21 15 1 25 9c5 4 7 11 7 11Zm0 0C57 21 49 1 39 9c-5 4-7 11-7 11Z"/>',
			'star' => '<path d="m32 5 8 17 19 3-14 14 3 19-16-9-17 9 4-19L5 25l19-3Z"/>',
			'gem' => '<path d="M15 12h34l12 16-29 31L3 28Zm-12 16h58M15 12l17 47 17-47M23 12l-8 16m26-16 8 16"/>',
			'leaf' => '<path d="M53 8C14 3 5 28 17 44S56 48 53 8ZM12 55l31-33M22 44l-1-16m11 6 15-1"/>',
			'cherry' => '<circle cx="19" cy="44" r="12"/><circle cx="45" cy="45" r="12"/><path d="M19 32 34 7c0 17 11 26 11 26M34 7c10-3 18 1 20 9-11 4-17 0-20-9Z"/>',
			'citrus' => '<circle cx="32" cy="36" r="23"/><path d="M32 16v40M12 36h40M18 22l28 28M18 50l28-28M32 13c0-8 7-10 14-7-1 7-7 9-14 7Z"/>',
			'berry' => '<path d="M11 24C4 37 24 58 32 60c9-3 28-24 21-36-9-14-32-14-42 0Zm5-8 16 6 16-6M32 6v16M22 31v2m20-2v2m-10 6v2m-7 6v2m15-2v2"/>',
			'grape' => '<circle cx="23" cy="24" r="9"/><circle cx="42" cy="24" r="9"/><circle cx="15" cy="40" r="9"/><circle cx="33" cy="40" r="9"/><circle cx="49" cy="40" r="9"/><circle cx="32" cy="55" r="8"/><path d="M32 17V4m0 7C44-1 55 6 52 12c-8 5-14 5-20-1Z"/>',
			'check' => '<path d="m13 32 13 13 26-27"/><circle cx="32" cy="32" r="28"/>',
			'key' => '<circle cx="23" cy="23" r="15"/><path d="m34 34 24 24m-8-8 7-7m-15-1 7-7"/>',
		);
		// Static drawing paths are owned by the plugin; the requested name never becomes markup.
		echo '<svg class="anar-game-icon" viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? $paths['star'] ) . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static allowlisted SVG.
	}

	public static function segments( $config, $game, $texts, $profile_id = 0 ) {
		$available = GameCampaign::availability( $config, $game, $profile_id );
		$segments = array();
		foreach ( $available['prizes'] as $id => $prize ) {
			$segments[] = array( 'id' => $id, 'label' => 'legacy' === $id ? $texts['reward_label'] : $prize['label'] );
		}
		$segments[] = array( 'id' => '', 'label' => $texts['wheel_miss_label'] );
		$base = $segments;
		$size = count( $segments );
		while ( $size < 6 ) {
			$segments = array_merge( $segments, $base );
			$size = count( $segments );
		}
		return array_slice( $segments, 0, 12 );
	}

	public static function render( $type, $config, $texts, $profile_id ) {
		$labels = array( 'wheel' => __( 'Spin to discover your gift', 'anar-toolkit' ), 'scratch' => __( 'Scratch the silver cover', 'anar-toolkit' ), 'boxes' => __( 'Choose a box to open', 'anar-toolkit' ), 'cards' => __( 'Choose a card to turn', 'anar-toolkit' ), 'slots' => __( 'Start the reels and see your result', 'anar-toolkit' ), 'treasure' => __( 'Choose a spot on the treasure map', 'anar-toolkit' ), 'daily' => __( 'A little gift for a new day', 'anar-toolkit' ), 'quiz' => __( 'Choose one answer', 'anar-toolkit' ), 'memory' => __( 'Find four pairs in 24 moves', 'anar-toolkit' ), 'milestone' => __( 'Your purchases bring you closer', 'anar-toolkit' ) );
		?>
		<p class="anar-game-instruction"><?php echo esc_html( $labels[ $type ] ); ?></p><div class="anar-game-stage">
		<?php
		if ( 'wheel' === $type ) :
			$segments = self::segments( $config, $type, $texts, $profile_id );
			$step = 360 / count( $segments );
			?>
			<div class="anar-wheel-frame"><span class="anar-wheel-pointer" aria-hidden="true"></span><div class="anar-wheel" data-wheel data-segments="<?php echo esc_attr( wp_json_encode( $segments ) ); ?>" style="--segments:<?php echo (int) count( $segments ); ?>">
			<?php foreach ( $segments as $i => $segment ) : ?>
				<div class="anar-wheel-sector" data-tone="<?php echo (int) ( $i % 3 ); ?>" style="--angle:<?php echo esc_attr( $i * $step ); ?>deg;--slice:<?php echo esc_attr( $step + 0.1 ); ?>deg"></div><span class="anar-wheel-label" style="--angle:<?php echo esc_attr( ( $i + 0.5 ) * $step ); ?>deg"><?php echo esc_html( $segment['label'] ); ?></span>
			<?php endforeach; ?>
			</div><span class="anar-wheel-hub"><?php self::icon( 'gift' ); ?></span></div><p class="anar-game-caption"><?php esc_html_e( 'The wheel shows possible prizes. Segment size does not indicate the chance of winning.', 'anar-toolkit' ); ?></p>
		<?php elseif ( 'scratch' === $type ) : ?>
			<div class="anar-scratch"><div class="anar-scratch-under"><?php self::icon( 'gem' ); ?><strong><?php esc_html_e( 'Your surprise awaits', 'anar-toolkit' ); ?></strong></div><canvas aria-label="<?php esc_attr_e( 'Scratch with your finger or mouse, or use the reveal button below.', 'anar-toolkit' ); ?>"></canvas></div><p class="anar-game-caption" data-scratch-progress><?php esc_html_e( 'Clear about 40% of the cover to reveal your result.', 'anar-toolkit' ); ?></p><button class="anar-game-secondary" type="button" data-pick="0"><?php esc_html_e( 'Reveal without scratching', 'anar-toolkit' ); ?></button>
		<?php elseif ( 'slots' === $type ) : ?>
			<div class="anar-slot-machine"><span class="anar-slot-lights" aria-hidden="true"></span><div class="anar-slots" data-slots>
			<?php
			foreach ( array( 'cherry', 'citrus', 'grape' ) as $fruit ) :
				?>
				<div class="anar-reel"><div class="anar-reel-strip">
				<?php
				foreach ( array( $fruit, 'berry', 'citrus', 'grape', $fruit ) as $item ) :
					?>
				<span data-fruit="<?php echo esc_attr( $item ); ?>"><?php self::icon( $item ); ?></span><?php endforeach; ?></div></div><?php endforeach; ?>
			</div><span class="anar-slot-base" aria-hidden="true"></span></div>
		<?php elseif ( 'quiz' === $type ) : ?>
			<div class="anar-quiz-heading"><?php self::icon( 'star' ); ?><h3><?php echo esc_html( $config['game_quiz_question'] ?: __( 'The store quiz will be available soon.', 'anar-toolkit' ) ); ?></h3></div><div class="anar-game-options">
			<?php
			foreach ( Games::quiz_options( $config ) as $i => $option ) :
				?>
				<button type="button" data-pick="<?php echo (int) $i + 1; ?>"><span><?php echo (int) $i + 1; ?></span><strong><?php echo esc_html( $option ); ?></strong><i aria-hidden="true"></i></button><?php endforeach; ?></div>
		<?php elseif ( in_array( $type, array( 'boxes', 'cards', 'treasure', 'memory' ), true ) ) : ?>
			<?php
			if ( 'memory' === $type ) :
				?>
				<div class="anar-memory-meter"><span><?php esc_html_e( 'Moves', 'anar-toolkit' ); ?> <b data-moves>0 / 24</b></span><span><?php esc_html_e( 'Pairs', 'anar-toolkit' ); ?> <b data-pairs>0 / 4</b></span></div>
				<?php
				foreach ( array( 'cherry' => __( 'Cherry', 'anar-toolkit' ), 'citrus' => __( 'Citrus', 'anar-toolkit' ), 'grape' => __( 'Grape', 'anar-toolkit' ), 'berry' => __( 'Berry', 'anar-toolkit' ) ) as $symbol => $label ) :
					?>
				<template data-symbol="<?php echo esc_attr( $symbol ); ?>" data-label="<?php echo esc_attr( $label ); ?>"><?php self::icon( $symbol ); ?></template>
					<?php
endforeach;
endif;
			?>
			<div class="anar-game-grid anar-grid-<?php echo esc_attr( $type ); ?>">
			<?php
			$count = 'memory' === $type ? 8 : ( 'treasure' === $type ? 9 : 3 );
			for ( $i = 0; $i < $count; ++$i ) :
				?>
				<button type="button" data-pick="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: item number. */ __( 'Choose item %d', 'anar-toolkit' ), $i + 1 ) ); ?>"><span class="anar-piece-inner"><span class="anar-piece-front">
				<?php
				if ( 'boxes' === $type ) :
					?>
					<span class="anar-gift-box"><i class="anar-gift-lid"></i><i class="anar-gift-body"></i><i class="anar-gift-bow"></i></span>
					<?php
else :
					self::icon( 'treasure' === $type ? 'key' : 'star' );
endif;
?>
				<span class="anar-piece-number"><?php echo (int) $i + 1; ?></span></span><span class="anar-piece-back" data-face><?php self::icon( 'gem' ); ?></span></span></button>
			<?php endfor; ?></div>
		<?php elseif ( 'daily' === $type ) : ?>
			<div class="anar-daily-gift"><div class="anar-daily-rays" aria-hidden="true"></div><span class="anar-daily-date"><?php echo esc_html( wp_date( 'j F' ) ); ?></span><div class="anar-daily-icon"><?php self::icon( 'gift' ); ?></div><strong><?php esc_html_e( 'Today is another opportunity', 'anar-toolkit' ); ?></strong></div>
			<?php
		else :
			$facts = ( new Repository() )->facts( $profile_id );
			$step = max( 1, (int) $config['game_milestone_orders'] );
			$orders = (int) $facts->orders;
			$progress = $orders;
			?>
			<div class="anar-milestones"><div class="anar-milestone-track"><span style="width:<?php echo esc_attr( min( 100, 100 * $progress / ( $step * 3 ) ) ); ?>%"></span></div>
			<?php
			foreach ( array( 'leaf', 'star', 'gift' ) as $i => $icon ) :
				?>
				<div class="anar-milestone-node <?php echo $orders >= $step * ( $i + 1 ) ? 'is-complete' : ''; ?>"><?php self::icon( $icon ); ?><span><?php echo (int) ( $step * ( $i + 1 ) ); ?></span></div><?php endforeach; ?></div><p><?php echo esc_html( sprintf( /* translators: 1: paid orders, 2: milestone interval. */ __( '%1$d paid orders. A gift at every %2$d orders.', 'anar-toolkit' ), $orders, $step ) ); ?></p>
		<?php endif; ?></div>
		<?php
		if ( in_array( $type, array( 'wheel', 'slots', 'daily', 'milestone' ), true ) ) :
			?>
			<button type="button" class="anar-game-start" data-pick="0"><?php echo esc_html( $texts['button_label'] ); ?><span aria-hidden="true">←</span></button>
			<?php
		endif;
	}
}
