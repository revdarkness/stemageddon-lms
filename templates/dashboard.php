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
<div class="sglms-dashboard">
	<?php if ( empty( $sglms_rows ) ) : ?>
		<div class="sglms-notice" style="border:1px solid #dcdcde;border-radius:6px;padding:20px;text-align:center;">
			<p><?php esc_html_e( 'You are not enrolled in any courses yet.', 'sglms' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( Sglms_Templates::page_url( 'sglms_page_catalog' ) ); ?>"><?php esc_html_e( 'Browse courses', 'sglms' ); ?></a></p>
		</div>
	<?php else : ?>
		<ul class="sglms-course-list" style="list-style:none;margin:0;padding:0;display:grid;gap:16px;">
			<?php
			foreach ( $sglms_rows as $sglms_row ) :
				$sglms_course = get_post( (int) $sglms_row->course_id );
				if ( ! $sglms_course || 'sglms_course' !== $sglms_course->post_type ) {
					continue;
				}
				$sglms_progress  = Sglms_Progress::get_course_progress( $sglms_user_id, (int) $sglms_row->course_id );
				$sglms_completed = ( Sglms_Enrollment::STATUS_COMPLETED === $sglms_row->status );
				?>
				<li class="sglms-course-card" style="border:1px solid #dcdcde;border-radius:8px;padding:16px;display:flex;gap:16px;align-items:center;">
					<?php if ( has_post_thumbnail( $sglms_course ) ) : ?>
						<div class="sglms-course-thumb" style="flex:0 0 120px;">
							<?php echo get_the_post_thumbnail( $sglms_course, array( 120, 80 ), array( 'style' => 'border-radius:6px;object-fit:cover;' ) ); ?>
						</div>
					<?php endif; ?>

					<div class="sglms-course-body" style="flex:1;">
						<h3 style="margin:0 0 6px;">
							<a href="<?php echo esc_url( get_permalink( $sglms_course ) ); ?>"><?php echo esc_html( get_the_title( $sglms_course ) ); ?></a>
							<?php if ( $sglms_completed ) : ?>
								<span class="sglms-badge" style="font-size:12px;background:#00a32a;color:#fff;border-radius:10px;padding:2px 8px;margin-left:6px;vertical-align:middle;"><?php esc_html_e( 'Completed', 'sglms' ); ?></span>
							<?php endif; ?>
						</h3>

						<div class="sglms-progress" aria-label="<?php esc_attr_e( 'Course progress', 'sglms' ); ?>" style="background:#e0e0e0;border-radius:6px;height:10px;overflow:hidden;margin:8px 0;">
							<div style="width:<?php echo esc_attr( $sglms_progress['percent'] ); ?>%;background:#2271b1;height:100%;"></div>
						</div>
						<p style="margin:0;color:#646970;font-size:13px;">
							<?php
							printf(
								/* translators: 1: percent, 2: completed lessons, 3: total lessons. */
								esc_html__( '%1$d%% complete — %2$d of %3$d lessons', 'sglms' ),
								(int) $sglms_progress['percent'],
								(int) $sglms_progress['completed'],
								(int) $sglms_progress['total']
							);
							?>
						</p>
					</div>

					<div class="sglms-course-action" style="flex:0 0 auto;display:flex;flex-direction:column;gap:6px;">
						<a class="button button-primary" href="<?php echo esc_url( get_permalink( $sglms_course ) ); ?>">
							<?php echo $sglms_completed ? esc_html__( 'Review', 'sglms' ) : esc_html__( 'Continue', 'sglms' ); ?>
						</a>
						<?php
						if ( $sglms_completed ) :
							$sglms_cert = Sglms_Certificate::get_active( $sglms_user_id, (int) $sglms_row->course_id );
							if ( $sglms_cert ) :
								?>
								<a class="button" href="<?php echo esc_url( Sglms_Certificate::download_url( $sglms_cert->cert_uid ) ); ?>">⬇ <?php esc_html_e( 'Certificate', 'sglms' ); ?></a>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
