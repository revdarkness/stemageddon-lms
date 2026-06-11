<?php
/**
 * Quiz template.
 *
 * Overridable at `yourtheme/sglms/quiz-single.php`.
 * Rendered via the_content for an accessible single sglms_quiz.
 *
 * The questions themselves are fetched from the /start REST route (which
 * strips correct answers), so this template only renders the shell; JS fills
 * it in and submits to /submit for server-side grading.
 *
 * $args: quiz (WP_Post), content (string, the instructions).
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

$sglms_quiz   = $args['quiz'];
$sglms_qid    = (int) $sglms_quiz->ID;
$sglms_intro  = isset( $args['content'] ) ? $args['content'] : '';
$sglms_user   = get_current_user_id();
$sglms_set    = Sglms_Quiz::get_settings( $sglms_qid );
$sglms_used   = Sglms_Quiz::count_attempts( $sglms_user, $sglms_qid );
$sglms_best   = Sglms_Quiz::best_percent( $sglms_user, $sglms_qid );
$sglms_limit  = (int) $sglms_set['attempt_limit'];
$sglms_left   = $sglms_limit > 0 ? max( 0, $sglms_limit - $sglms_used ) : null;
$sglms_course = (int) $sglms_set['course_id'];
?>
<div class="sglms sglms-quizwrap">

	<?php if ( $sglms_intro ) : ?>
		<div class="sglms-quiz-intro"><?php echo wp_kses_post( $sglms_intro ); ?></div>
	<?php endif; ?>

	<div class="sglms-quiz-meta">
		<span><?php echo esc_html( sprintf( /* translators: %d: passing score. */ __( 'Passing score: %d%%', 'sglms' ), (int) $sglms_set['pass_score'] ) ); ?></span>
		<?php if ( null !== $sglms_best ) : ?>
			<span><?php echo esc_html( sprintf( /* translators: %d: best score. */ __( 'Your best: %d%%', 'sglms' ), (int) $sglms_best ) ); ?></span>
		<?php endif; ?>
		<?php if ( null !== $sglms_left ) : ?>
			<span><?php echo esc_html( sprintf( /* translators: %d: attempts left. */ _n( '%d attempt left', '%d attempts left', $sglms_left, 'sglms' ), $sglms_left ) ); ?></span>
		<?php endif; ?>
	</div>

	<?php if ( null !== $sglms_left && 0 === $sglms_left ) : ?>
		<div class="sglms-locked">
			<p><?php esc_html_e( 'You have used all of your attempts for this quiz.', 'sglms' ); ?></p>
		</div>
	<?php else : ?>
		<div
			class="sglms-quiz"
			data-sglms-quiz="<?php echo esc_attr( $sglms_qid ); ?>"
			data-course="<?php echo esc_attr( $sglms_course ); ?>"
		>
			<noscript><?php esc_html_e( 'This quiz requires JavaScript.', 'sglms' ); ?></noscript>
			<p class="sglms-quiz-loading"><?php esc_html_e( 'Loading quiz…', 'sglms' ); ?></p>
		</div>
	<?php endif; ?>
</div>
<?php
unset( $sglms_user, $sglms_used );
