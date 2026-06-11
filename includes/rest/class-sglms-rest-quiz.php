<?php
/**
 * REST controller: quiz attempts.
 *
 * Namespace: sglms/v1
 *   POST /quiz/{id}/start    returns answer-stripped questions + attempt state
 *   POST /quiz/{id}/submit   grades server-side, records the attempt
 *
 * Correct answers are never sent to the client. Grading and attempt-limit
 * enforcement happen entirely on the server.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Rest_Quiz
 */
class Sglms_Rest_Quiz {

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
			'/quiz/(?P<id>\d+)/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'can_access_quiz' ),
			)
		);

		register_rest_route(
			self::NS,
			'/quiz/(?P<id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => array( $this, 'can_access_quiz' ),
				'args'                => array(
					'answers' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * Permission: logged in + can access the quiz (enrolled in its course).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_access_quiz( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'sglms_not_logged_in', __( 'Authentication required.', 'sglms' ), array( 'status' => 401 ) );
		}
		$quiz_id = (int) $request['id'];
		if ( 'sglms_quiz' !== get_post_type( $quiz_id ) ) {
			return new WP_Error( 'sglms_no_quiz', __( 'Quiz not found.', 'sglms' ), array( 'status' => 404 ) );
		}
		if ( ! Sglms_Gatekeeper::can_access( $quiz_id ) ) {
			return new WP_Error( 'sglms_forbidden', __( 'You do not have access to this quiz.', 'sglms' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Whether the user has attempts remaining.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return array{used:int,limit:int,left:int|null,allowed:bool}
	 */
	private function attempt_state( $user_id, $quiz_id ) {
		$settings = Sglms_Quiz::get_settings( $quiz_id );
		$used     = Sglms_Quiz::count_attempts( $user_id, $quiz_id );
		$limit    = (int) $settings['attempt_limit'];
		$left     = $limit > 0 ? max( 0, $limit - $used ) : null;
		return array(
			'used'    => $used,
			'limit'   => $limit,
			'left'    => $left,
			'allowed' => ( 0 === $limit || $used < $limit ),
		);
	}

	/**
	 * Start: return public questions + attempt state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( $request ) {
		$quiz_id = (int) $request['id'];
		$state   = $this->attempt_state( get_current_user_id(), $quiz_id );

		if ( ! $state['allowed'] ) {
			return new WP_Error( 'sglms_no_attempts', __( 'You have no attempts remaining.', 'sglms' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response(
			array(
				'quiz_id'   => $quiz_id,
				'questions' => Sglms_Quiz::public_questions( $quiz_id ),
				'attempts'  => $state,
			)
		);
	}

	/**
	 * Submit: grade server-side, record the attempt, return result.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( $request ) {
		$quiz_id = (int) $request['id'];
		$user_id = get_current_user_id();
		$state   = $this->attempt_state( $user_id, $quiz_id );

		if ( ! $state['allowed'] ) {
			return new WP_Error( 'sglms_no_attempts', __( 'You have no attempts remaining.', 'sglms' ), array( 'status' => 403 ) );
		}

		$answers = $request['answers'];
		$answers = is_array( $answers ) ? $answers : array();

		$result = Sglms_Quiz::grade( $quiz_id, $answers );
		Sglms_Quiz::record_attempt( $user_id, $quiz_id, $result, $answers );

		// Passing a quiz may complete the course.
		$course_id = (int) get_post_meta( $quiz_id, '_sglms_course_id', true );
		if ( $course_id && $result['passed'] ) {
			Sglms_Progress::check_course_completion( $user_id, $course_id );
		}

		$new_state = $this->attempt_state( $user_id, $quiz_id );

		return rest_ensure_response(
			array(
				'quiz_id'  => $quiz_id,
				'score'    => $result['score'],
				'max'      => $result['max'],
				'percent'  => $result['percent'],
				'passed'   => $result['passed'],
				'results'  => $result['results'],
				'attempts' => $new_state,
			)
		);
	}
}
