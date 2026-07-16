<?php
/**
 * LMS shell template.
 *
 * A self-contained dark "lab bench HUD" document used on all LMS screens
 * (catalog, course landing, lesson player, quiz, dashboard). The plugin owns
 * the whole page here: no theme header/footer. The inner content is produced
 * by the_content (filtered by Sglms_Frontend::render_single for our types,
 * or the page shortcode for the catalog/dashboard pages).
 *
 * Overridable at `yourtheme/sglms/shell.php`.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

$sglms_is_player = is_singular( 'sglms_lesson' );
$sglms_is_course = is_singular( 'sglms_course' );

// Course context for the top bar: progress (player) and difficulty stars.
$sglms_pct  = null;
$sglms_cid  = 0;
$sglms_diff = array(
	'level' => 0,
	'label' => '',
);

if ( $sglms_is_player ) {
	$sglms_cid = Sglms_Frontend::course_of( get_queried_object_id() );
	if ( $sglms_cid && class_exists( 'Sglms_Progress' ) ) {
		$sglms_p   = Sglms_Progress::get_course_progress( get_current_user_id(), $sglms_cid );
		$sglms_pct = isset( $sglms_p['percent'] ) ? (int) $sglms_p['percent'] : 0;
	}
} elseif ( $sglms_is_course ) {
	$sglms_cid = get_queried_object_id();
}

if ( $sglms_cid ) {
	$sglms_diff = Sglms_Frontend::difficulty_level( $sglms_cid );
}

// Where the brand mark links: dashboard if set, else catalog, else home.
$sglms_home = home_url( '/' );
foreach ( array( 'sglms_page_dashboard', 'sglms_page_catalog' ) as $sglms_opt ) {
	$sglms_pid = (int) get_option( $sglms_opt );
	if ( $sglms_pid ) {
		$sglms_home = get_permalink( $sglms_pid );
		break;
	}
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'sglms-shell-body' ); ?>>

<div class="sglms-shell<?php echo $sglms_is_player ? ' sglms-shell--player' : ''; ?>">

	<header class="sglms-topbar">
		<a class="sglms-topbar__brand" href="<?php echo esc_url( $sglms_home ); ?>">
			<span class="sglms-reactor" aria-hidden="true"></span>
			<span class="sglms-wordmark">STEM<span class="sglms-wordmark__accent">AGEDDON</span></span>
			<span class="sglms-topbar__divider" aria-hidden="true"></span>
			<span class="sglms-topbar__kicker">LEARN</span>
		</a>

		<div class="sglms-topbar__center">
			<?php
			// Difficulty stars (shared between the player pill and the course pill).
			$sglms_stars = '';
			if ( $sglms_diff['level'] > 0 ) {
				$sglms_stars = '<span class="sglms-stars" role="img" aria-label="'
					. esc_attr( sprintf( /* translators: %s: difficulty level. */ __( 'Difficulty: %s', 'sglms' ), $sglms_diff['label'] ) )
					. '" title="' . esc_attr( $sglms_diff['label'] ) . '">';
				for ( $sglms_i = 1; $sglms_i <= 3; $sglms_i++ ) {
					$sglms_on       = $sglms_i <= $sglms_diff['level'] ? ' is-on' : '';
					$sglms_stars   .= '<span class="sglms-star' . $sglms_on . '" aria-hidden="true">&#9733;</span>';
				}
				$sglms_stars .= '</span>';
			}
			?>
			<?php if ( $sglms_is_player && null !== $sglms_pct ) : ?>
				<div class="sglms-topbar__progress">
					<span class="sglms-topbar__count"><?php echo esc_html( $sglms_pct ); ?>% complete</span>
					<span class="sglms-topbar__track"><span style="width:<?php echo esc_attr( $sglms_pct ); ?>%;"></span></span>
					<?php if ( $sglms_stars ) : ?>
						<span class="sglms-topbar__divider sglms-topbar__divider--pill" aria-hidden="true"></span>
						<?php echo $sglms_stars; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
					<?php endif; ?>
				</div>
			<?php elseif ( $sglms_stars ) : ?>
				<div class="sglms-topbar__progress sglms-topbar__progress--diff">
					<span class="sglms-topbar__count"><?php echo esc_html( $sglms_diff['label'] ); ?></span>
					<span class="sglms-topbar__divider sglms-topbar__divider--pill" aria-hidden="true"></span>
					<?php echo $sglms_stars; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="sglms-topbar__utility">
			<?php if ( is_user_logged_in() ) : ?>
				<?php
				$sglms_u       = wp_get_current_user();
				$sglms_initial = strtoupper( substr( $sglms_u->display_name ? $sglms_u->display_name : $sglms_u->user_login, 0, 1 ) );
				?>
				<a class="sglms-account" href="<?php echo esc_url( $sglms_home ); ?>">
					<span class="sglms-account__dot" aria-hidden="true"></span><?php echo esc_html( $sglms_initial ); ?> &middot; MEMBER
				</a>
			<?php else : ?>
				<a class="sglms-account" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">SIGN IN</a>
			<?php endif; ?>
		</div>
	</header>

	<div class="sglms-shell__body">
		<?php
		if ( have_posts() ) {
			while ( have_posts() ) {
				the_post();
				the_content();
			}
		}
		?>
	</div>

</div>

<?php wp_footer(); ?>
</body>
</html>
