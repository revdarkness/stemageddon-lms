<?php
/**
 * Front-end controller for the catalog, course landing, and lesson player.
 *
 * Rather than override the page template (get_header()/get_footer() are not
 * valid in block themes, which production uses), the catalog/landing/player
 * render by filtering the_content for our post types. The theme keeps
 * rendering its own header, footer, and chrome; we own the content area.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Frontend
 */
class Sglms_Frontend {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'the_content', array( $this, 'render_single' ), 30 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'comments_open', array( $this, 'scope_lesson_comments' ), 10, 2 );
	}

	/**
	 * Swap in the course landing or lesson player for our post types.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function render_single( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post ) {
			return $content;
		}

		if ( 'sglms_course' === $post->post_type && is_singular( 'sglms_course' ) ) {
			return Sglms_Templates::render(
				'course-single.php',
				array(
					'course'  => $post,
					'content' => $content,
				)
			);
		}

		if ( 'sglms_lesson' === $post->post_type && is_singular( 'sglms_lesson' ) ) {
			// Non-accessible lessons fall through to the gatekeeper's teaser.
			if ( ! Sglms_Gatekeeper::can_access( $post->ID ) ) {
				return $content;
			}
			return Sglms_Templates::render(
				'lesson-player.php',
				array(
					'lesson'  => $post,
					'content' => $content,
				)
			);
		}

		if ( 'sglms_quiz' === $post->post_type && is_singular( 'sglms_quiz' ) ) {
			if ( ! Sglms_Gatekeeper::can_access( $post->ID ) ) {
				return $content;
			}
			return Sglms_Templates::render(
				'quiz-single.php',
				array(
					'quiz'    => $post,
					'content' => $content,
				)
			);
		}

		return $content;
	}

	/**
	 * Enqueue scoped CSS/JS where the LMS front end appears.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		wp_enqueue_style( 'sglms-frontend', SGLMS_URL . 'assets/css/frontend.css', array(), SGLMS_VERSION );
		wp_enqueue_script( 'sglms-frontend', SGLMS_URL . 'assets/js/frontend.js', array(), SGLMS_VERSION, true );
		wp_localize_script(
			'sglms-frontend',
			'sglmsData',
			array(
				'restUrl' => esc_url_raw( rest_url( 'sglms/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'saving'    => __( 'Saving…', 'sglms' ),
					'saved'     => __( 'Saved', 'sglms' ),
					'completed' => __( 'Completed', 'sglms' ),
					'submit'    => __( 'Submit quiz', 'sglms' ),
					'error'     => __( 'Something went wrong. Please try again.', 'sglms' ),
				),
			)
		);
	}

	/**
	 * Whether to load front-end assets on the current view.
	 *
	 * @return bool
	 */
	private function should_enqueue() {
		if ( is_singular( array( 'sglms_course', 'sglms_lesson', 'sglms_quiz' ) ) ) {
			return true;
		}
		$post = get_post();
		if ( $post instanceof WP_Post ) {
			if ( has_shortcode( $post->post_content, 'sglms_catalog' ) || has_shortcode( $post->post_content, 'sglms_dashboard' ) ) {
				return true;
			}
		}
		// Also the auto-created catalog/dashboard pages.
		$page_id = get_queried_object_id();
		foreach ( array( 'sglms_page_catalog', 'sglms_page_dashboard' ) as $opt ) {
			if ( $page_id && (int) get_option( $opt ) === $page_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Restrict lesson comments to users who can access the lesson.
	 *
	 * @param bool $open    Whether comments are open.
	 * @param int  $post_id Post ID.
	 * @return bool
	 */
	public function scope_lesson_comments( $open, $post_id ) {
		if ( 'sglms_lesson' !== get_post_type( $post_id ) ) {
			return $open;
		}
		return $open && Sglms_Gatekeeper::can_access( $post_id );
	}

	/* --------------------------------------------------------------------- *
	 * Navigation helpers (used by templates)
	 * --------------------------------------------------------------------- */

	/**
	 * Ordered lesson IDs for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return int[]
	 */
	public static function ordered_lessons( $course_id ) {
		return Sglms_Modules_Schema::ordered_lesson_ids( $course_id );
	}

	/**
	 * First lesson ID of a course, or 0.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	public static function first_lesson( $course_id ) {
		$ids = self::ordered_lessons( $course_id );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The course a lesson belongs to.
	 *
	 * @param int $lesson_id Lesson ID.
	 * @return int
	 */
	public static function course_of( $lesson_id ) {
		return (int) get_post_meta( $lesson_id, '_sglms_course_id', true );
	}

	/**
	 * Previous/next lesson IDs within a course.
	 *
	 * @param int $course_id Course ID.
	 * @param int $lesson_id Current lesson ID.
	 * @return array{0:int,1:int} [prev, next] (0 when none).
	 */
	public static function adjacent( $course_id, $lesson_id ) {
		$ids = self::ordered_lessons( $course_id );
		$pos = array_search( (int) $lesson_id, $ids, true );
		if ( false === $pos ) {
			return array( 0, 0 );
		}
		$prev = $pos > 0 ? (int) $ids[ $pos - 1 ] : 0;
		$next = ( $pos < count( $ids ) - 1 ) ? (int) $ids[ $pos + 1 ] : 0;
		return array( $prev, $next );
	}
}
