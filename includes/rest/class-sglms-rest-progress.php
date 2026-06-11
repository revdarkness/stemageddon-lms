<?php
/**
 * REST controller: enrollment + progress.
 *
 * Namespace: sglms/v1
 *   POST /enroll/{course}                  self-enroll in a free course
 *   POST /progress/lesson/{id}/complete    mark a lesson complete
 *   GET  /progress/course/{course}         course progress for current user
 *
 * All mutating routes are cookie-authenticated and require a valid
 * X-WP-Nonce; permission callbacks enforce login and enrollment.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Rest_Progress
 */
class Sglms_Rest_Progress {

	const NS = 'sglms/v1';

	/**
	 * Hook route registration.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/enroll/(?P<course>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'enroll' ),
				'permission_callback' => array( $this, 'require_login' ),
				'args'                => array(
					'course' => array(
						'validate_callback' => static function ( $v ) {
							return is_numeric( $v );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/progress/lesson/(?P<id>\d+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_lesson' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);

		register_rest_route(
			self::NS,
			'/progress/course/(?P<course>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'course_progress' ),
				'permission_callback' => array( $this, 'require_login' ),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Permission
	 * --------------------------------------------------------------------- */

	/**
	 * Require a logged-in user.
	 *
	 * @return true|WP_Error
	 */
	public function require_login() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'sglms_not_logged_in', __( 'Authentication required.', 'sglms' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/* --------------------------------------------------------------------- *
	 * Handlers
	 * --------------------------------------------------------------------- */

	/**
	 * Self-enroll in a free course.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function enroll( $request ) {
		$course_id = (int) $request['course'];
		$result    = Sglms_Enrollment::self_enroll( $course_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'enrolled'  => true,
				'course_id' => $course_id,
				'status'    => Sglms_Enrollment::get_status( get_current_user_id(), $course_id ),
			)
		);
	}

	/**
	 * Mark a lesson complete.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_lesson( $request ) {
		$lesson_id = (int) $request['id'];
		$user_id   = get_current_user_id();

		$result = Sglms_Progress::mark_lesson_complete( $user_id, $lesson_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$course_id = (int) get_post_meta( $lesson_id, '_sglms_course_id', true );
		return rest_ensure_response(
			array(
				'lesson_id' => $lesson_id,
				'complete'  => true,
				'progress'  => $course_id ? Sglms_Progress::get_course_progress( $user_id, $course_id ) : null,
				'course'    => array(
					'id'        => $course_id,
					'completed' => $course_id ? ( Sglms_Enrollment::STATUS_COMPLETED === Sglms_Enrollment::get_status( $user_id, $course_id ) ) : false,
				),
			)
		);
	}

	/**
	 * Course progress for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function course_progress( $request ) {
		$course_id = (int) $request['course'];
		$user_id   = get_current_user_id();

		if ( ! Sglms_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return new WP_Error( 'sglms_not_enrolled', __( 'You are not enrolled in this course.', 'sglms' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( Sglms_Progress::get_course_progress( $user_id, $course_id ) );
	}
}
