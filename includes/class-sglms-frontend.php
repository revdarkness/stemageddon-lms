<?php
/**
 * Front-end controller for the catalog, course landing, and lesson player.
 *
 * The catalog/landing/player/quiz/dashboard render inside a self-contained dark
 * "lab bench HUD" shell: on LMS screens the plugin takes over template_include
 * and emits its own standalone HTML document (wp_head()/wp_footer(), no theme
 * header/footer). This keeps the experience identical on any theme, block or
 * classic, and avoids get_header()/get_footer() (invalid in block themes).
 * The inner content is still produced by filtering the_content for our types,
 * so the shell stays theme-agnostic.
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
		add_filter( 'template_include', array( $this, 'shell_template' ), 99 );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_on_lms' ), 99 );
	}

	/**
	 * Take over the page template with the LMS shell on LMS screens.
	 *
	 * @param string $template Resolved theme template path.
	 * @return string
	 */
	public function shell_template( $template ) {
		if ( $this->is_lms_screen() && file_exists( SGLMS_TEMPLATES . 'shell.php' ) ) {
			return SGLMS_TEMPLATES . 'shell.php';
		}
		return $template;
	}

	/**
	 * Suppress the WordPress admin bar inside the immersive shell.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public function hide_admin_bar_on_lms( $show ) {
		return $this->is_lms_screen() ? false : $show;
	}

	/**
	 * Whether the current request is an LMS screen the shell should own.
	 *
	 * @return bool
	 */
	public function is_lms_screen() {
		if ( is_admin() || is_embed() || is_feed() ) {
			return false;
		}
		if ( is_singular( array( 'sglms_course', 'sglms_lesson', 'sglms_quiz' ) ) ) {
			return true;
		}
		$post = get_post();
		if ( $post instanceof WP_Post && ( has_shortcode( $post->post_content, 'sglms_catalog' ) || has_shortcode( $post->post_content, 'sglms_dashboard' ) ) ) {
			return true;
		}
		$page_id = get_queried_object_id();
		foreach ( array( 'sglms_page_catalog', 'sglms_page_dashboard' ) as $opt ) {
			if ( $page_id && (int) get_option( $opt ) === $page_id ) {
				return true;
			}
		}
		return false;
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

		// Design system fonts (Space Grotesk, Hanken Grotesk, JetBrains Mono).
		// Filterable so a site can self-host or disable the Google Fonts call.
		$sglms_fonts = apply_filters(
			'sglms_google_fonts_url',
			'https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@400;500;700&family=Space+Grotesk:wght@400;500;600;700&display=swap'
		);
		$sglms_css_deps = array();
		if ( $sglms_fonts ) {
			wp_enqueue_style( 'sglms-fonts', $sglms_fonts, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts is versionless.
			$sglms_css_deps[] = 'sglms-fonts';
		}

		// Version assets by file mtime so edits bust the browser cache; fall back
		// to the plugin version if the file is unreadable.
		$sglms_css_path = SGLMS_PATH . 'assets/css/frontend.css';
		$sglms_js_path  = SGLMS_PATH . 'assets/js/frontend.js';
		$sglms_css_ver  = file_exists( $sglms_css_path ) ? (string) filemtime( $sglms_css_path ) : SGLMS_VERSION;
		$sglms_js_ver   = file_exists( $sglms_js_path ) ? (string) filemtime( $sglms_js_path ) : SGLMS_VERSION;

		wp_enqueue_style( 'sglms-frontend', SGLMS_URL . 'assets/css/frontend.css', $sglms_css_deps, $sglms_css_ver );
		wp_enqueue_script( 'sglms-frontend', SGLMS_URL . 'assets/js/frontend.js', array(), $sglms_js_ver, true );
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
		return $this->is_lms_screen();
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
	 * Course difficulty as a filled-star level (0-3) plus its label.
	 *
	 * Maps the sglms_difficulty taxonomy (Beginner / Intermediate / Advanced)
	 * to 1 / 2 / 3 filled stars. Returns level 0 when no difficulty is set.
	 *
	 * @param int $course_id Course ID.
	 * @return array{level:int,label:string}
	 */
	public static function difficulty_level( $course_id ) {
		$terms = wp_get_post_terms( (int) $course_id, 'sglms_difficulty' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array(
				'level' => 0,
				'label' => '',
			);
		}
		$map   = array(
			'beginner'     => 1,
			'intermediate' => 2,
			'advanced'     => 3,
		);
		$term  = $terms[0];
		$level = isset( $map[ $term->slug ] ) ? $map[ $term->slug ] : 0;
		return array(
			'level' => $level,
			'label' => $term->name,
		);
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
