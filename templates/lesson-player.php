<?php
/**
 * Lesson player template.
 *
 * Overridable at `yourtheme/sglms/lesson-player.php`.
 * Rendered via the_content for an accessible single sglms_lesson.
 *
 * $args: lesson (WP_Post), content (string, the lesson body).
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

$sglms_lesson  = $args['lesson'];
$sglms_lid     = (int) $sglms_lesson->ID;
$sglms_body    = isset( $args['content'] ) ? $args['content'] : '';
$sglms_user    = get_current_user_id();
$sglms_cid     = Sglms_Frontend::course_of( $sglms_lid );
$sglms_course  = $sglms_cid ? get_post( $sglms_cid ) : null;
$sglms_modules = $sglms_cid ? Sglms_Modules_Schema::get( $sglms_cid ) : array();
$sglms_done    = Sglms_Progress::is_lesson_complete( $sglms_user, $sglms_lid );
$sglms_avail   = Sglms_Drip::is_available( $sglms_user, $sglms_lid );
$sglms_video   = get_post_meta( $sglms_lid, '_sglms_video', true );
$sglms_attach  = json_decode( (string) get_post_meta( $sglms_lid, '_sglms_attachments', true ), true );
$sglms_attach  = is_array( $sglms_attach ) ? array_map( 'intval', $sglms_attach ) : array();
$sglms_prog    = $sglms_cid ? Sglms_Progress::get_course_progress( $sglms_user, $sglms_cid ) : array( 'percent' => 0 );

list( $sglms_prev, $sglms_next ) = $sglms_cid ? Sglms_Frontend::adjacent( $sglms_cid, $sglms_lid ) : array( 0, 0 );
?>
<div class="sglms sglms-player" data-course="<?php echo esc_attr( $sglms_cid ); ?>">

	<aside class="sglms-player__sidebar">
		<?php if ( $sglms_course ) : ?>
			<a class="sglms-player__courselink" href="<?php echo esc_url( get_permalink( $sglms_cid ) ); ?>">&larr; <?php echo esc_html( get_the_title( $sglms_course ) ); ?></a>
		<?php endif; ?>

		<div class="sglms-progress" aria-label="<?php esc_attr_e( 'Course progress', 'sglms' ); ?>">
			<div class="sglms-progress__bar" data-sglms-progressbar style="width:<?php echo esc_attr( $sglms_prog['percent'] ); ?>%;"></div>
		</div>
		<p class="sglms-player__progresstext"><span data-sglms-progresstext><?php echo esc_html( $sglms_prog['percent'] ); ?></span>% <?php esc_html_e( 'complete', 'sglms' ); ?></p>

		<nav class="sglms-player__nav">
			<?php foreach ( $sglms_modules as $sglms_module ) : ?>
				<p class="sglms-player__module"><?php echo esc_html( $sglms_module['title'] ? $sglms_module['title'] : __( 'Module', 'sglms' ) ); ?></p>
				<ul>
					<?php
					foreach ( $sglms_module['lessons'] as $sglms_mlid ) :
						$sglms_ml = get_post( $sglms_mlid );
						if ( ! $sglms_ml ) {
							continue;
						}
						$sglms_mdone   = Sglms_Progress::is_lesson_complete( $sglms_user, $sglms_mlid );
						$sglms_mlocked = ! Sglms_Drip::is_available( $sglms_user, $sglms_mlid );
						$sglms_current = ( (int) $sglms_mlid === $sglms_lid );
						?>
						<li class="sglms-navrow<?php echo $sglms_current ? ' is-current' : ''; ?><?php echo $sglms_mdone ? ' is-complete' : ''; ?><?php echo $sglms_mlocked ? ' is-locked' : ''; ?>">
							<span class="sglms-navrow__icon" aria-hidden="true"><?php echo $sglms_mlocked ? '🔒' : ( $sglms_mdone ? '✓' : '○' ); ?></span>
							<?php if ( $sglms_mlocked ) : ?>
								<span><?php echo esc_html( get_the_title( $sglms_ml ) ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( get_permalink( $sglms_mlid ) ); ?>"><?php echo esc_html( get_the_title( $sglms_ml ) ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</nav>
	</aside>

	<main class="sglms-player__main">
		<h1 class="sglms-player__title"><?php echo esc_html( get_the_title( $sglms_lesson ) ); ?></h1>

		<?php if ( ! $sglms_avail ) : ?>
			<div class="sglms-locked">
				<p>🔒 <?php esc_html_e( 'This lesson is not available yet.', 'sglms' ); ?></p>
				<?php
				$sglms_unlock = Sglms_Drip::unlock_date( $sglms_user, $sglms_lid );
				if ( $sglms_unlock ) :
					?>
					<p class="sglms-note"><?php echo esc_html( sprintf( /* translators: %s: date. */ __( 'Unlocks on %s', 'sglms' ), date_i18n( get_option( 'date_format' ), strtotime( $sglms_unlock ) ) ) ); ?></p>
				<?php else : ?>
					<p class="sglms-note"><?php esc_html_e( 'Complete the previous lessons to unlock it.', 'sglms' ); ?></p>
				<?php endif; ?>
			</div>
		<?php else : ?>

			<?php if ( $sglms_video ) : ?>
				<div class="sglms-player__video">
					<?php
					$sglms_embed = wp_oembed_get( $sglms_video );
					echo $sglms_embed ? $sglms_embed : '<video controls src="' . esc_url( $sglms_video ) . '" style="max-width:100%;"></video>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oEmbed HTML.
					?>
				</div>
			<?php endif; ?>

			<div class="sglms-tabs">
				<div class="sglms-tabs__nav" role="tablist">
					<button class="sglms-tabs__tab is-active" data-sglms-tab="overview" role="tab"><?php esc_html_e( 'Lesson', 'sglms' ); ?></button>
					<button class="sglms-tabs__tab" data-sglms-tab="resources" role="tab"><?php esc_html_e( 'Resources', 'sglms' ); ?></button>
					<button class="sglms-tabs__tab" data-sglms-tab="notes" role="tab"><?php esc_html_e( 'Notes', 'sglms' ); ?></button>
				</div>

				<div class="sglms-tabs__panel is-active" data-sglms-panel="overview">
					<div class="sglms-lesson__body"><?php echo wp_kses_post( $sglms_body ); ?></div>
				</div>

				<div class="sglms-tabs__panel" data-sglms-panel="resources" hidden>
					<?php
					// Only whitelisted lesson attachments, served via expiring tokens.
					$sglms_lesson_files = array_values( array_filter( $sglms_attach, array( 'Sglms_Downloads', 'is_allowed' ) ) );
					$sglms_course_files = $sglms_cid ? Sglms_Downloads::course_resources( $sglms_cid ) : array();
					?>
					<?php if ( empty( $sglms_lesson_files ) ) : ?>
						<p class="sglms-empty"><?php esc_html_e( 'No resources for this lesson.', 'sglms' ); ?></p>
					<?php else : ?>
						<ul class="sglms-resources">
							<?php foreach ( $sglms_lesson_files as $sglms_att_id ) : ?>
								<li><a href="<?php echo esc_url( Sglms_Downloads::file_url( $sglms_att_id, $sglms_cid ) ); ?>">⬇ <?php echo esc_html( get_the_title( $sglms_att_id ) ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( ! empty( $sglms_course_files ) ) : ?>
						<p style="margin-top:12px;">
							<a class="sglms-btn" href="<?php echo esc_url( Sglms_Downloads::zip_url( $sglms_cid ) ); ?>">
								⬇ <?php echo esc_html( sprintf( /* translators: %d: file count. */ _n( 'Download all course files (%d)', 'Download all course files (%d)', count( $sglms_course_files ), 'sglms' ), count( $sglms_course_files ) ) ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>

				<div class="sglms-tabs__panel" data-sglms-panel="notes" hidden>
					<label class="sglms-note-label" for="sglms-notes-<?php echo esc_attr( $sglms_lid ); ?>"><?php esc_html_e( 'Your private notes', 'sglms' ); ?></label>
					<textarea id="sglms-notes-<?php echo esc_attr( $sglms_lid ); ?>" class="sglms-notes" data-sglms-notes="<?php echo esc_attr( $sglms_lid ); ?>" rows="8"><?php echo esc_textarea( Sglms_Notes::get( $sglms_user, $sglms_lid ) ); ?></textarea>
					<div class="sglms-notes__actions">
						<button type="button" class="sglms-btn" data-sglms-notes-save="<?php echo esc_attr( $sglms_lid ); ?>"><?php esc_html_e( 'Save notes', 'sglms' ); ?></button>
						<span class="sglms-notes__status" data-sglms-notes-status></span>
					</div>
				</div>
			</div>

			<div class="sglms-player__footer">
				<div class="sglms-player__complete">
					<button type="button" class="sglms-btn sglms-btn--primary" data-sglms-complete="<?php echo esc_attr( $sglms_lid ); ?>"<?php echo $sglms_done ? ' hidden' : ''; ?>>
						<?php esc_html_e( 'Mark complete', 'sglms' ); ?>
					</button>
					<span class="sglms-complete-badge" data-sglms-complete-badge<?php echo $sglms_done ? '' : ' hidden'; ?>>✓ <?php esc_html_e( 'Completed', 'sglms' ); ?></span>
				</div>

				<div class="sglms-player__prevnext">
					<?php if ( $sglms_prev ) : ?>
						<a class="sglms-btn" href="<?php echo esc_url( get_permalink( $sglms_prev ) ); ?>">&larr; <?php esc_html_e( 'Previous', 'sglms' ); ?></a>
					<?php endif; ?>
					<?php if ( $sglms_next ) : ?>
						<a class="sglms-btn" href="<?php echo esc_url( get_permalink( $sglms_next ) ); ?>"><?php esc_html_e( 'Next', 'sglms' ); ?> &rarr;</a>
					<?php endif; ?>
				</div>
			</div>

			<?php
			// Threaded discussion, scoped to enrolled users by comments_open filter.
			// Only on the real singular view (needs the main query context).
			if ( is_singular( 'sglms_lesson' ) && comments_open( $sglms_lid ) ) {
				ob_start();
				comments_template();
				echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core comment markup.
			}
			?>

		<?php endif; ?>
	</main>
</div>
