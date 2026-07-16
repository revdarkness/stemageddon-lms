<?php
/**
 * Access-denied template.
 *
 * Overridable by a theme at `yourtheme/sglms/access-denied.php`.
 *
 * Available via $args:
 *   - post_id    (int)    The protected post, if any.
 *   - protection (string) The protection rule that applied.
 *   - course_id  (int)    The course gating this content, if any.
 *   - inline     (bool)   True when rendered inside a page (shortcode) rather
 *                         than as a standalone denied view.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

$sglms_course_id  = isset( $args['course_id'] ) ? (int) $args['course_id'] : 0;
$sglms_protection = isset( $args['protection'] ) ? (string) $args['protection'] : '';
$sglms_logged_in  = is_user_logged_in();

// Resolve a course to point an enroll CTA at.
if ( ! $sglms_course_id && 0 === strpos( $sglms_protection, 'course:' ) ) {
	$sglms_course_id = (int) substr( $sglms_protection, 7 );
}
$sglms_course = $sglms_course_id ? get_post( $sglms_course_id ) : null;

$sglms_redirect = ( isset( $args['post_id'] ) && $args['post_id'] ) ? get_permalink( (int) $args['post_id'] ) : '';
?>
<section class="sglms sglms-access-denied">
	<div class="sglms-access-denied__panel">
		<div class="sglms-access-denied__lock" aria-hidden="true">&#128274;</div>
		<h2><?php esc_html_e( 'This content is restricted', 'sglms' ); ?></h2>

		<?php if ( $sglms_course instanceof WP_Post ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: course title. */
					esc_html__( 'You need to be enrolled in %s to view this.', 'sglms' ),
					'<strong>' . esc_html( get_the_title( $sglms_course ) ) . '</strong>'
				);
				?>
			</p>
		<?php elseif ( 'members' === $sglms_protection ) : ?>
			<p><?php esc_html_e( 'This content is available to logged-in members.', 'sglms' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'You do not have permission to view this content.', 'sglms' ); ?></p>
		<?php endif; ?>

		<div class="sglms-access-denied__actions">
			<?php if ( ! $sglms_logged_in ) : ?>
				<a class="sglms-btn sglms-btn--primary" href="<?php echo esc_url( wp_login_url( $sglms_redirect ) ); ?>">
					<?php esc_html_e( 'Log in', 'sglms' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( $sglms_course instanceof WP_Post ) : ?>
				<a class="sglms-btn" href="<?php echo esc_url( get_permalink( $sglms_course ) ); ?>">
					<?php esc_html_e( 'View course & enroll', 'sglms' ); ?>
				</a>
			<?php else : ?>
				<a class="sglms-btn" href="<?php echo esc_url( Sglms_Templates::page_url( 'sglms_page_catalog' ) ); ?>">
					<?php esc_html_e( 'Browse courses', 'sglms' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</section>
<?php unset( $sglms_redirect, $sglms_logged_in ); ?>
