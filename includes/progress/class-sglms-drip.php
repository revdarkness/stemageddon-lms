<?php
/**
 * Drip engine.
 *
 * Decides whether a lesson is *available* to an enrolled user yet. This is
 * distinct from access control: an enrolled user can_access the course, but
 * a lesson may still be locked until a date or a prerequisite is met.
 *
 * Course drip rules live in the `_sglms_drip_rules` meta:
 *
 *   { "mode": "none" }                       // everything open (default)
 *   { "mode": "sequential" }                 // each lesson unlocks when the
 *                                            //   previous required lesson is done
 *   { "mode": "schedule",
 *     "lessons": { "<lesson_id>": <days> },  // days after enrollment
 *     "modules": { "<module_id>": <days> } }
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Drip
 */
class Sglms_Drip {

	/**
	 * Whether a lesson is available to a user right now.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return bool
	 */
	public static function is_available( $user_id, $lesson_id ) {
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;
		$course_id = (int) get_post_meta( $lesson_id, '_sglms_course_id', true );

		if ( ! $course_id ) {
			return true;
		}
		// Managers/instructors always see everything.
		if ( user_can( $user_id, 'edit_post', $course_id ) ) {
			return true;
		}

		$rules = self::rules( $course_id );
		$mode  = isset( $rules['mode'] ) ? $rules['mode'] : 'none';

		switch ( $mode ) {
			case 'sequential':
				return self::sequential_available( $user_id, $course_id, $lesson_id );
			case 'schedule':
				return self::schedule_available( $user_id, $course_id, $lesson_id, $rules );
			case 'none':
			default:
				return true;
		}
	}

	/**
	 * The GMT datetime a lesson unlocks, or '' if available now / unknown.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return string
	 */
	public static function unlock_date( $user_id, $lesson_id ) {
		$course_id = (int) get_post_meta( $lesson_id, '_sglms_course_id', true );
		if ( ! $course_id ) {
			return '';
		}
		$rules = self::rules( $course_id );
		if ( ( isset( $rules['mode'] ) ? $rules['mode'] : 'none' ) !== 'schedule' ) {
			return '';
		}

		$days = self::scheduled_days( $lesson_id, $rules );
		if ( null === $days ) {
			return '';
		}
		$enrolled_at = Sglms_Enrollment::enrolled_at( $user_id, $course_id );
		if ( ! $enrolled_at ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( $enrolled_at ) + ( $days * DAY_IN_SECONDS ) );
	}

	/* --------------------------------------------------------------------- *
	 * Modes
	 * --------------------------------------------------------------------- */

	/**
	 * Sequential: a lesson unlocks once every prior required lesson is done.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @param int $lesson_id Lesson ID.
	 * @return bool
	 */
	private static function sequential_available( $user_id, $course_id, $lesson_id ) {
		$ordered   = Sglms_Modules_Schema::ordered_lesson_ids( $course_id );
		$completed = Sglms_Progress::get_completed_lesson_ids( $user_id, $course_id );

		foreach ( $ordered as $id ) {
			if ( (int) $id === $lesson_id ) {
				return true; // Reached the target with all priors satisfied.
			}
			$required = get_post_meta( $id, '_sglms_required', true );
			$is_req   = ( '' === $required || $required );
			if ( $is_req && ! in_array( (int) $id, $completed, true ) ) {
				return false; // A prior required lesson is incomplete.
			}
		}
		return true; // Lesson not in curriculum order; don't lock it.
	}

	/**
	 * Schedule: a lesson unlocks N days after enrollment.
	 *
	 * @param int   $user_id   User ID.
	 * @param int   $course_id Course ID.
	 * @param int   $lesson_id Lesson ID.
	 * @param array $rules     Drip rules.
	 * @return bool
	 */
	private static function schedule_available( $user_id, $course_id, $lesson_id, $rules ) {
		$days = self::scheduled_days( $lesson_id, $rules );
		if ( null === $days || $days <= 0 ) {
			return true;
		}
		$enrolled_at = Sglms_Enrollment::enrolled_at( $user_id, $course_id );
		if ( ! $enrolled_at ) {
			return true; // Can't compute; fail open within an enrolled course.
		}
		$unlock = strtotime( $enrolled_at ) + ( $days * DAY_IN_SECONDS );
		return time() >= $unlock;
	}

	/**
	 * Resolve the scheduled days for a lesson (lesson rule, else its module).
	 *
	 * @param int   $lesson_id Lesson ID.
	 * @param array $rules     Drip rules.
	 * @return int|null Days, or null if no rule applies.
	 */
	private static function scheduled_days( $lesson_id, $rules ) {
		if ( isset( $rules['lessons'][ (string) $lesson_id ] ) ) {
			return (int) $rules['lessons'][ (string) $lesson_id ];
		}
		$module_id = get_post_meta( $lesson_id, '_sglms_module_id', true );
		if ( $module_id && isset( $rules['modules'][ $module_id ] ) ) {
			return (int) $rules['modules'][ $module_id ];
		}
		return null;
	}

	/**
	 * Decode a course's drip rules.
	 *
	 * @param int $course_id Course ID.
	 * @return array
	 */
	private static function rules( $course_id ) {
		$raw = get_post_meta( $course_id, '_sglms_drip_rules', true );
		if ( empty( $raw ) ) {
			return array( 'mode' => 'none' );
		}
		$decoded = json_decode( (string) $raw, true );
		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : array( 'mode' => 'none' );
	}
}
