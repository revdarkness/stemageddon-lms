<?php
/**
 * REST controller: per-user lesson notes.
 *
 * Namespace: sglms/v1
 *   GET  /notes/{lesson_id}   read the current user's note
 *   PUT  /notes/{lesson_id}   save the current user's note
 *
 * Requires login + access to the lesson (enrollment). A user can only ever
 * touch their own note.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Rest_Notes
 */
class Sglms_Rest_Notes {

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
			'/notes/(?P<lesson_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_note' ),
					'permission_callback' => array( $this, 'can_access_lesson' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_note' ),
					'permission_callback' => array( $this, 'can_access_lesson' ),
					'args'                => array(
						'content' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Permission: logged in and can access the lesson.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_access_lesson( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'sglms_not_logged_in', __( 'Authentication required.', 'sglms' ), array( 'status' => 401 ) );
		}
		$lesson_id = (int) $request['lesson_id'];
		if ( 'sglms_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'sglms_no_lesson', __( 'Lesson not found.', 'sglms' ), array( 'status' => 404 ) );
		}
		if ( ! Sglms_Gatekeeper::can_access( $lesson_id ) ) {
			return new WP_Error( 'sglms_forbidden', __( 'You do not have access to this lesson.', 'sglms' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_note( $request ) {
		$lesson_id = (int) $request['lesson_id'];
		return rest_ensure_response(
			array(
				'lesson_id' => $lesson_id,
				'content'   => Sglms_Notes::get( get_current_user_id(), $lesson_id ),
			)
		);
	}

	/**
	 * PUT handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_note( $request ) {
		$lesson_id = (int) $request['lesson_id'];
		$stored    = Sglms_Notes::save( get_current_user_id(), $lesson_id, (string) $request['content'] );
		return rest_ensure_response(
			array(
				'lesson_id' => $lesson_id,
				'content'   => $stored,
				'saved'     => true,
			)
		);
	}
}
