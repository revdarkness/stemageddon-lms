<?php
/**
 * Access control gatekeeper.
 *
 * Single authority for "can this user see this content," plus the filters
 * that stop protected content leaking through every surface WordPress
 * exposes: direct URLs, the_content, excerpts, search, feeds, and REST.
 *
 * Enrollment is not known until Phase 3, so course access is resolved
 * through the `sglms_user_can_access_course` filter, which the enrollment
 * manager will answer. Until then, only users who can edit the course
 * (instructors/admins) pass — everyone else is denied, which is the safe
 * default for a security layer.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Gatekeeper
 */
class Sglms_Gatekeeper {

	/**
	 * Post types that are course content gated by enrollment.
	 */
	const GATED_TYPES = array( 'sglms_lesson', 'sglms_quiz' );

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'guard_singular' ), 1 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 20, 2 );
		add_filter( 'the_posts', array( $this, 'filter_listings' ), 10, 2 );

		// REST: blank out protected payloads for every public post type.
		add_action( 'init', array( $this, 'register_rest_guards' ), 20 );
	}

	/* --------------------------------------------------------------------- *
	 * Authorization
	 * --------------------------------------------------------------------- */

	/**
	 * Can a user access a given post?
	 *
	 * @param int      $post_id Post ID.
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return bool
	 */
	public static function can_access( $post_id, $user_id = null ) {
		$post_id = (int) $post_id;
		$user_id = ( null === $user_id ) ? get_current_user_id() : (int) $user_id;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return true;
		}

		// Anyone who can edit the content always sees it.
		if ( user_can( $user_id, 'edit_post', $post_id ) ) {
			return true;
		}

		// Course content is gated by enrollment in its course.
		if ( in_array( $post->post_type, self::GATED_TYPES, true ) ) {
			$course_id = (int) get_post_meta( $post_id, '_sglms_course_id', true );
			if ( $course_id ) {
				return self::can_access_course( $course_id, $user_id );
			}
			// Unassigned course content: members-only.
			return $user_id > 0;
		}

		// Explicit protection meta on any page/post.
		$protection = get_post_meta( $post_id, '_sglms_protection', true );
		return self::evaluate( $protection ? $protection : 'none', $user_id );
	}

	/**
	 * Evaluate a protection string for a user.
	 *
	 * @param string $protection One of none|members|course:{id}|level:{slug}.
	 * @param int    $user_id    User ID.
	 * @return bool
	 */
	public static function evaluate( $protection, $user_id ) {
		$protection = (string) $protection;

		if ( '' === $protection || 'none' === $protection ) {
			return true;
		}
		if ( 'members' === $protection ) {
			return $user_id > 0;
		}
		if ( 0 === strpos( $protection, 'course:' ) ) {
			return self::can_access_course( (int) substr( $protection, 7 ), $user_id );
		}
		if ( 0 === strpos( $protection, 'level:' ) ) {
			$level = substr( $protection, 6 );
			/**
			 * Whether a user holds a membership level. Answered by the
			 * membership layer; defaults to false (deny).
			 *
			 * @param bool   $has_level Default false.
			 * @param string $level     Level slug.
			 * @param int    $user_id   User ID.
			 */
			return (bool) apply_filters( 'sglms_user_has_level', false, $level, $user_id );
		}
		return true;
	}

	/**
	 * Can a user access a course (i.e. is enrolled / can manage it)?
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID.
	 * @return bool
	 */
	public static function can_access_course( $course_id, $user_id ) {
		$course_id = (int) $course_id;
		if ( $course_id && user_can( $user_id, 'edit_post', $course_id ) ) {
			return true;
		}
		/**
		 * Whether a user may access a course's gated content. The enrollment
		 * manager (Phase 3) answers this; default false (deny).
		 *
		 * @param bool $can       Default false.
		 * @param int  $course_id Course ID.
		 * @param int  $user_id   User ID.
		 */
		return (bool) apply_filters( 'sglms_user_can_access_course', false, $course_id, $user_id );
	}

	/* --------------------------------------------------------------------- *
	 * Surface guards
	 * --------------------------------------------------------------------- */

	/**
	 * Block direct access to a protected singular view.
	 *
	 * Redirects to the configured access-denied page, carrying the blocked
	 * post id so that page can show a contextual login/enroll CTA. A redirect
	 * is used rather than rendering in place because get_header()/get_footer()
	 * are not valid in block themes (the production theme is a block theme),
	 * and this keeps the plugin portable across classic and block themes
	 * without leaking content or returning a 404.
	 *
	 * @return void
	 */
	public function guard_singular() {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || self::can_access( $post_id ) ) {
			return;
		}

		$denied_id  = (int) get_option( 'sglms_page_denied' );
		$denied_url = Sglms_Templates::page_url( 'sglms_page_denied' );

		// Redirect to the denied page (guard against redirecting it to itself).
		if ( $denied_id && $post_id !== $denied_id && $denied_url ) {
			nocache_headers();
			wp_safe_redirect( add_query_arg( 'sglms_from', $post_id, $denied_url ) );
			exit;
		}

		// Fallback when no denied page is configured: a bare 403.
		status_header( 403 );
		nocache_headers();
		wp_die(
			esc_html__( 'You do not have permission to view this content.', 'sglms' ),
			esc_html__( 'Restricted', 'sglms' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Replace protected content with a teaser everywhere the_content runs.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		$post_id = get_the_ID();
		if ( ! $post_id || self::can_access( $post_id ) ) {
			return $content;
		}
		return $this->teaser( $post_id );
	}

	/**
	 * Replace protected excerpts.
	 *
	 * @param string  $excerpt Excerpt.
	 * @param WP_Post $post    Post object.
	 * @return string
	 */
	public function filter_excerpt( $excerpt, $post = null ) {
		$post_id = $post ? $post->ID : get_the_ID();
		if ( ! $post_id || self::can_access( $post_id ) ) {
			return $excerpt;
		}
		return esc_html__( 'This content is restricted.', 'sglms' );
	}

	/**
	 * Strip inaccessible posts from search, feed, and REST collections.
	 *
	 * Singular views are handled by guard_singular(); stripping there would
	 * cause a 404, so this only filters list-type queries.
	 *
	 * @param WP_Post[] $posts Posts.
	 * @param WP_Query  $query Query.
	 * @return WP_Post[]
	 */
	public function filter_listings( $posts, $query ) {
		if ( empty( $posts ) || ! ( $query instanceof WP_Query ) ) {
			return $posts;
		}

		$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( ! $query->is_search() && ! $query->is_feed() && ! $is_rest ) {
			return $posts;
		}
		// Don't interfere with a singular REST/main request.
		if ( $query->is_singular() ) {
			return $posts;
		}

		$filtered = array();
		foreach ( $posts as $post ) {
			if ( self::can_access( $post->ID ) ) {
				$filtered[] = $post;
			}
		}
		return $filtered;
	}

	/**
	 * Register rest_prepare guards for all public post types.
	 *
	 * @return void
	 */
	public function register_rest_guards() {
		$types = get_post_types( array( 'show_in_rest' => true ) );
		foreach ( $types as $type ) {
			add_filter( "rest_prepare_{$type}", array( $this, 'filter_rest_response' ), 10, 3 );
		}
	}

	/**
	 * Blank out protected fields in a single-item REST response.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public function filter_rest_response( $response, $post, $request ) {
		if ( ! $post || self::can_access( $post->ID ) ) {
			return $response;
		}

		$data    = $response->get_data();
		$message = esc_html__( 'This content is restricted.', 'sglms' );

		if ( isset( $data['content']['rendered'] ) ) {
			$data['content']['rendered']  = $message;
			$data['content']['protected'] = true;
		}
		if ( isset( $data['excerpt']['rendered'] ) ) {
			$data['excerpt']['rendered'] = $message;
		}
		$response->set_data( $data );
		unset( $request );
		return $response;
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * A short "restricted" teaser with a login/enroll link.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function teaser( $post_id ) {
		$denied = Sglms_Templates::render(
			'access-denied.php',
			array(
				'post_id'    => $post_id,
				'protection' => get_post_meta( $post_id, '_sglms_protection', true ),
				'course_id'  => (int) get_post_meta( $post_id, '_sglms_course_id', true ),
				'inline'     => true,
			)
		);
		if ( $denied ) {
			return $denied;
		}
		return '<p>' . esc_html__( 'This content is restricted.', 'sglms' ) . '</p>';
	}
}
