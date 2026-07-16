<?php
/**
 * Student dashboard template.
 *
 * Overridable at `yourtheme/sglms/dashboard.php`.
 * Rendered by the [sglms_dashboard] shortcode for logged-in users.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

$sglms_user_id = get_current_user_id();
$sglms_rows    = Sglms_Enrollment::get_user_enrollments(
	$sglms_user_id,
	array( Sglms_Enrollment::STATUS_ENROLLED, Sglms_Enrollment::STATUS_COMPLETED )
);
?>
<div class="sglms sglms-dashboard">
	<div class="sglms-dash__head">
		<h2 class="sglms-dash__title"><?php esc_html_e( 'My Courses', 'sglms' ); ?></h2>
		<?php if ( ! empty( $sglms_rows ) ) : ?>
			<span class="sglms-dash__count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of enrolled courses. */
						_n( '%d enrolled', '%d enrolled', count( $sglms_rows ), 'sglms' ),
						count( $sglms_rows )
					)
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php if ( empty( $sglms_rows ) ) : ?>
		<div class="sglms-dash__empty">
			<p><?php esc_html_e( 'You are not enrolled in any courses yet.', 'sglms' ); ?></p>
			<a class="sglms-btn sglms-btn--primary" href="<?php echo esc_url( Sglms_Templates::page_url( 'sglms_page_catalog' ) ); ?>"><?php esc_html_e( 'Browse courses', 'sglms' ); ?></a>
		</div>
	<?php else : ?>
		<ul class="sglms-dash__list">
			<?php
			foreach ( $sglms_rows as $sglms_row ) :
				$sglms_course = get_post( (int) $sglms_row->course_id );
				if ( ! $sglms_course || 'sglms_course' !== $sglms_course->post_type ) {
					continue;
				}
				$sglms_progress  = Sglms_Progress::get_course_progress( $sglms_user_id, (int) $sglms_row->course_id );
				$sglms_completed = ( Sglms_Enrollment::STATUS_COMPLETED === $sglms_row->status );
				$sglms_has_thumb = has_post_thumbnail( $sglms_course );
				// Lessons done but a required quiz still outstanding — surface it so
				// students don't stall at "100%" wondering where the certificate is.
				$sglms_quiz_pending = (
					! $sglms_completed
					&& (int) $sglms_progress['lessons_done'] >= (int) $sglms_progress['lessons_total']
					&& (int) $sglms_progress['quizzes_total'] > (int) $sglms_progress['quizzes_done']
				);
				?>
				<li class="sglms-dash__card">
					<div class="sglms-dash__thumb<?php echo $sglms_has_thumb ? '' : ' sglms-dash__thumb--placeholder'; ?>">
						<?php
						if ( $sglms_has_thumb ) {
							echo get_the_post_thumbnail( $sglms_course, array( 264, 168 ) );
						}
						?>
					</div>

					<div class="sglms-dash__body">
						<h3 class="sglms-dash__name">
							<a href="<?php echo esc_url( get_permalink( $sglms_course ) ); ?>"><?php echo esc_html( get_the_title( $sglms_course ) ); ?></a>
							<?php if ( $sglms_completed ) : ?>
								<span class="sglms-badge--done"><?php esc_html_e( 'Completed', 'sglms' ); ?></span>
							<?php endif; ?>
						</h3>

						<div class="sglms-progress" aria-label="<?php esc_attr_e( 'Course progress', 'sglms' ); ?>">
							<div class="sglms-progress__bar" style="width:<?php echo esc_attr( $sglms_progress['percent'] ); ?>%;"></div>
						</div>
						<p class="sglms-dash__meta">
							<?php
							printf(
								/* translators: 1: percent, 2: completed lessons, 3: total lessons. */
								esc_html__( '%1$d%% complete · %2$d of %3$d lessons', 'sglms' ),
								(int) $sglms_progress['percent'],
								(int) $sglms_progress['lessons_done'],
								(int) $sglms_progress['lessons_total']
							);
							if ( $sglms_quiz_pending ) {
								echo ' &middot; <span class="sglms-dash__flag">' . esc_html__( 'quiz required', 'sglms' ) . '</span>';
							}
							?>
						</p>
					</div>

					<div class="sglms-dash__actions">
						<a class="sglms-btn sglms-btn--primary" href="<?php echo esc_url( get_permalink( $sglms_course ) ); ?>">
							<?php
							if ( $sglms_completed ) {
								esc_html_e( 'Review', 'sglms' );
							} elseif ( $sglms_quiz_pending ) {
								esc_html_e( 'Quiz required to finish', 'sglms' );
							} else {
								esc_html_e( 'Continue', 'sglms' );
							}
							?>
						</a>
						<?php
						if ( $sglms_completed ) :
							$sglms_cert = Sglms_Certificate::get_active( $sglms_user_id, (int) $sglms_row->course_id );
							if ( $sglms_cert ) :
								?>
								<a class="sglms-btn" href="<?php echo esc_url( Sglms_Certificate::download_url( $sglms_cert->cert_uid ) ); ?>">&#11015; <?php esc_html_e( 'Certificate', 'sglms' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
