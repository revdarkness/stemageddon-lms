<?php
/**
 * Progress tracker.
 *
 * Records per-user lesson completion, computes course progress, and detects
 * course completion (all required lessons done + all required quizzes
 * passed), firing completion through the enrollment manager.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Progress
 */
class Sglms_Progress {

	const STATUS_COMPLETE = 'complete';

	/**
	 * No hooks needed at construct time; methods are invoked by REST/admin.
	 */
	public function __construct() {}

	/**
	 * Progress table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sglms_progress';
	}

	/**
	 * Mark a lesson complete for a user.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return true|WP_Error
	 */
	public static function mark_lesson_complete( $user_id, $lesson_id ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;

		if ( 'sglms_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'sglms_no_lesson', __( 'Lesson not found.', 'sglms' ), array( 'status' => 404 ) );
		}

		$course_id = (int) get_post_meta( $lesson_id, '_sglms_course_id', true );
		if ( $course_id && ! Sglms_Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return new WP_Error( 'sglms_not_enrolled', __( 'You are not enrolled in this course.', 'sglms' ), array( 'status' => 403 ) );
		}

		$table = self::table();
		if ( self::is_lesson_complete( $user_id, $lesson_id ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE user_id = %d AND lesson_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$lesson_id
			)
		);

		$now = current_time( 'mysql', true );
		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'       => self::STATUS_COMPLETE,
					'completed_at' => $now,
				),
				array(
					'user_id'   => $user_id,
					'lesson_id' => $lesson_id,
				),
				array( '%s', '%s' ),
				array( '%d', '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'user_id'      => $user_id,
					'lesson_id'    => $lesson_id,
					'course_id'    => $course_id,
					'status'       => self::STATUS_COMPLETE,
					'completed_at' => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s' )
			);
		}

		/**
		 * Fires when a lesson is marked complete.
		 *
		 * @param int $user_id   User ID.
		 * @param int $lesson_id Lesson ID.
		 * @param int $course_id Course ID.
		 */
		do_action( 'sglms_lesson_completed', $user_id, $lesson_id, $course_id );

		if ( $course_id ) {
			self::check_course_completion( $user_id, $course_id );
		}

		return true;
	}

	/**
	 * Whether a lesson is complete for a user.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return bool
	 */
	public static function is_lesson_complete( $user_id, $lesson_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM `{$table}` WHERE user_id = %d AND lesson_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $lesson_id
			)
		);
		return self::STATUS_COMPLETE === $status;
	}

	/**
	 * Completed lesson IDs for a user in a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return int[]
	 */
	public static function get_completed_lesson_ids( $user_id, $course_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT lesson_id FROM `{$table}` WHERE user_id = %d AND course_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $course_id,
				self::STATUS_COMPLETE
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Compute course progress for a user.
	 *
	 * Progress is measured against REQUIRED lessons in the curriculum.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array{percent:int,completed:int,total:int}
	 */
	public static function get_course_progress( $user_id, $course_id ) {
		$required = self::required_lesson_ids( $course_id );
		$total    = count( $required );
		if ( 0 === $total ) {
			return array(
				'percent'   => 0,
				'completed' => 0,
				'total'     => 0,
			);
		}

		$done      = array_intersect( $required, self::get_completed_lesson_ids( $user_id, $course_id ) );
		$completed = count( $done );

		return array(
			'percent'   => (int) round( $completed / $total * 100 ),
			'completed' => $completed,
			'total'     => $total,
		);
	}

	/**
	 * Detect and record course completion.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public static function check_course_completion( $user_id, $course_id ) {
		$required = self::required_lesson_ids( $course_id );
		if ( empty( $required ) ) {
			return; // No curriculum yet; nothing to complete.
		}

		$completed = self::get_completed_lesson_ids( $user_id, $course_id );
		foreach ( $required as $lesson_id ) {
			if ( ! in_array( $lesson_id, $completed, true ) ) {
				return;
			}
		}

		// All required lessons done; require any required course quizzes passed.
		foreach ( self::required_quiz_ids( $course_id ) as $quiz_id ) {
			if ( ! self::has_passed_quiz( $user_id, $quiz_id ) ) {
				return;
			}
		}

		Sglms_Enrollment::complete( $user_id, $course_id );
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Required lesson IDs for a course (ordered), filtered by _sglms_required.
	 *
	 * @param int $course_id Course ID.
	 * @return int[]
	 */
	public static function required_lesson_ids( $course_id ) {
		$ids = Sglms_Modules_Schema::ordered_lesson_ids( $course_id );
		$out = array();
		foreach ( $ids as $lesson_id ) {
			$required = get_post_meta( $lesson_id, '_sglms_required', true );
			// Default to required when the meta is unset.
			if ( '' === $required || $required ) {
				$out[] = (int) $lesson_id;
			}
		}
		return $out;
	}

	/**
	 * Required quiz IDs attached to a course.
	 *
	 * Quizzes carry _sglms_course_id once the quiz builder (Phase 5) lands.
	 *
	 * @param int $course_id Course ID.
	 * @return int[]
	 */
	public static function required_quiz_ids( $course_id ) {
		$quizzes = get_posts(
			array(
				'post_type'   => 'sglms_quiz',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_sglms_course_id',   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => (int) $course_id,     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map( 'intval', (array) $quizzes );
	}

	/**
	 * Whether a user has a passing attempt on a quiz.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return bool
	 */
	public static function has_passed_quiz( $user_id, $quiz_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sglms_quiz_attempts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$passed = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND quiz_id = %d AND passed = 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $quiz_id
			)
		);
		return (int) $passed > 0;
	}
}
