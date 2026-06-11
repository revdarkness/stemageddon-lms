<?php
/**
 * Course landing template.
 *
 * Overridable at `yourtheme/sglms/course-single.php`.
 * Rendered via the_content for a single sglms_course.
 *
 * $args: course (WP_Post), content (string, the editor description).
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

$sglms_course   = $args['course'];
$sglms_cid      = (int) $sglms_course->ID;
$sglms_desc     = isset( $args['content'] ) ? $args['content'] : '';
$sglms_user     = get_current_user_id();
$sglms_status   = $sglms_user ? Sglms_Enrollment::get_status( $sglms_user, $sglms_cid ) : '';
$sglms_enrolled = in_array( $sglms_status, array( Sglms_Enrollment::STATUS_ENROLLED, Sglms_Enrollment::STATUS_COMPLETED ), true );
$sglms_price    = get_post_meta( $sglms_cid, '_sglms_price_mode', true );
$sglms_price    = $sglms_price ? $sglms_price : 'free';
$sglms_modules  = Sglms_Modules_Schema::get( $sglms_cid );
$sglms_first    = Sglms_Frontend::first_lesson( $sglms_cid );
$sglms_duration = get_post_meta( $sglms_cid, '_sglms_est_duration', true );
$sglms_diff     = wp_get_post_terms( $sglms_cid, 'sglms_difficulty' );
$sglms_count    = count( Sglms_Frontend::ordered_lessons( $sglms_cid ) );
$sglms_progress = $sglms_enrolled ? Sglms_Progress::get_course_progress( $sglms_user, $sglms_cid ) : null;
?>
<div class="sglms sglms-course">

	<div class="sglms-course__hero">
		<?php if ( has_post_thumbnail( $sglms_course ) ) : ?>
			<div class="sglms-course__media"><?php echo get_the_post_thumbnail( $sglms_course, 'large' ); ?></div>
		<?php endif; ?>

		<div class="sglms-course__intro">
			<div class="sglms-course__badges">
				<?php if ( ! empty( $sglms_diff ) && ! is_wp_error( $sglms_diff ) ) : ?>
					<span class="sglms-chip"><?php echo esc_html( $sglms_diff[0]->name ); ?></span>
				<?php endif; ?>
				<?php if ( 'free' === $sglms_price ) : ?>
					<span class="sglms-chip sglms-chip--free"><?php esc_html_e( 'Free', 'sglms' ); ?></span>
				<?php endif; ?>
			</div>

			<ul class="sglms-course__facts">
				<li><strong><?php echo esc_html( $sglms_count ); ?></strong> <?php esc_html_e( 'lessons', 'sglms' ); ?></li>
				<?php if ( $sglms_duration ) : ?>
					<li><?php echo esc_html( $sglms_duration ); ?></li>
				<?php endif; ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: enrolled count. */ _n( '%d enrolled', '%d enrolled', Sglms_Enrollment::count_active( $sglms_cid ), 'sglms' ), Sglms_Enrollment::count_active( $sglms_cid ) ) ); ?></li>
			</ul>

			<div class="sglms-course__cta">
				<?php if ( $sglms_enrolled ) : ?>
					<?php if ( is_array( $sglms_progress ) ) : ?>
						<div class="sglms-progress" aria-label="<?php esc_attr_e( 'Course progress', 'sglms' ); ?>">
							<div class="sglms-progress__bar" style="width:<?php echo esc_attr( $sglms_progress['percent'] ); ?>%;"></div>
						</div>
						<p class="sglms-course__progresstext"><?php echo esc_html( sprintf( /* translators: %d: percent complete. */ __( '%d%% complete', 'sglms' ), (int) $sglms_progress['percent'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( $sglms_first ) : ?>
						<a class="sglms-btn sglms-btn--primary" href="<?php echo esc_url( get_permalink( $sglms_first ) ); ?>"><?php esc_html_e( 'Continue learning', 'sglms' ); ?></a>
					<?php endif; ?>
				<?php elseif ( ! $sglms_user ) : ?>
					<a class="sglms-btn sglms-btn--primary" href="<?php echo esc_url( wp_login_url( get_permalink( $sglms_cid ) ) ); ?>"><?php esc_html_e( 'Log in to enroll', 'sglms' ); ?></a>
				<?php elseif ( 'free' === $sglms_price ) : ?>
					<button type="button" class="sglms-btn sglms-btn--primary" data-sglms-enroll="<?php echo esc_attr( $sglms_cid ); ?>"><?php esc_html_e( 'Enroll for free', 'sglms' ); ?></button>
				<?php else : ?>
					<?php
					/** Paid checkout is provided by a payment adapter (Phase 8). */
					$sglms_checkout = apply_filters( 'sglms_course_checkout_url', '', $sglms_cid );
					?>
					<?php if ( $sglms_checkout ) : ?>
						<a class="sglms-btn sglms-btn--primary" href="<?php echo esc_url( $sglms_checkout ); ?>"><?php esc_html_e( 'Enroll', 'sglms' ); ?></a>
					<?php else : ?>
						<p class="sglms-note"><?php esc_html_e( 'Enrollment for this course is handled by the site administrator.', 'sglms' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php if ( $sglms_desc ) : ?>
		<section class="sglms-course__section">
			<h2><?php esc_html_e( 'About this course', 'sglms' ); ?></h2>
			<div class="sglms-course__desc"><?php echo wp_kses_post( $sglms_desc ); ?></div>
		</section>
	<?php endif; ?>

	<section class="sglms-course__section">
		<h2><?php esc_html_e( 'Syllabus', 'sglms' ); ?></h2>
		<?php if ( empty( $sglms_modules ) ) : ?>
			<p class="sglms-empty"><?php esc_html_e( 'The curriculum is being prepared.', 'sglms' ); ?></p>
		<?php else : ?>
			<div class="sglms-accordion">
				<?php foreach ( $sglms_modules as $sglms_i => $sglms_module ) : ?>
					<div class="sglms-accordion__item">
						<button type="button" class="sglms-accordion__head" aria-expanded="<?php echo 0 === $sglms_i ? 'true' : 'false'; ?>">
							<span><?php echo esc_html( $sglms_module['title'] ? $sglms_module['title'] : __( 'Module', 'sglms' ) ); ?></span>
							<span class="sglms-accordion__count"><?php echo esc_html( count( $sglms_module['lessons'] ) ); ?></span>
						</button>
						<ul class="sglms-accordion__body"<?php echo 0 === $sglms_i ? '' : ' hidden'; ?>>
							<?php
							foreach ( $sglms_module['lessons'] as $sglms_lid ) :
								$sglms_l = get_post( $sglms_lid );
								if ( ! $sglms_l ) {
									continue;
								}
								$sglms_done = $sglms_enrolled && Sglms_Progress::is_lesson_complete( $sglms_user, $sglms_lid );
								?>
								<li class="sglms-lessonrow<?php echo $sglms_done ? ' is-complete' : ''; ?>">
									<span class="sglms-lessonrow__check" aria-hidden="true"><?php echo $sglms_done ? '✓' : '○'; ?></span>
									<?php if ( $sglms_enrolled ) : ?>
										<a href="<?php echo esc_url( get_permalink( $sglms_lid ) ); ?>"><?php echo esc_html( get_the_title( $sglms_l ) ); ?></a>
									<?php else : ?>
										<span><?php echo esc_html( get_the_title( $sglms_l ) ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<?php if ( $sglms_enrolled ) : ?>
		<?php $sglms_resources = Sglms_Downloads::course_resources( $sglms_cid ); ?>
		<?php if ( ! empty( $sglms_resources ) ) : ?>
			<section class="sglms-course__section sglms-course__resources">
				<h2><?php esc_html_e( 'Course Resources', 'sglms' ); ?></h2>
				<ul class="sglms-resources">
					<?php foreach ( $sglms_resources as $sglms_att_id ) : ?>
						<li><a href="<?php echo esc_url( Sglms_Downloads::file_url( $sglms_att_id, $sglms_cid ) ); ?>">⬇ <?php echo esc_html( get_the_title( $sglms_att_id ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
				<p><a class="sglms-btn" href="<?php echo esc_url( Sglms_Downloads::zip_url( $sglms_cid ) ); ?>">⬇ <?php esc_html_e( 'Download all', 'sglms' ); ?></a></p>
			</section>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	$sglms_author = (int) $sglms_course->post_author;
	$sglms_bio    = $sglms_author ? get_the_author_meta( 'description', $sglms_author ) : '';
	if ( $sglms_author ) :
		?>
		<section class="sglms-course__section sglms-course__instructor">
			<h2><?php esc_html_e( 'Instructor', 'sglms' ); ?></h2>
			<div class="sglms-instructor">
				<?php echo get_avatar( $sglms_author, 64 ); ?>
				<div>
					<p class="sglms-instructor__name"><?php echo esc_html( get_the_author_meta( 'display_name', $sglms_author ) ); ?></p>
					<?php if ( $sglms_bio ) : ?>
						<p class="sglms-instructor__bio"><?php echo esc_html( $sglms_bio ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>
</div>
