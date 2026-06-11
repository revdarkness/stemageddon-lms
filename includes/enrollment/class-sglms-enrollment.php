<?php
/**
 * Enrollment manager.
 *
 * Owns the enrollments table and is the authority on who is enrolled in
 * what. Closes the Phase 2 loop by answering the gatekeeper's
 * `sglms_user_can_access_course` filter: an enrolled (or completed) user
 * gains access to a course's gated content.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Enrollment
 */
class Sglms_Enrollment {

	const STATUS_ENROLLED  = 'enrolled';
	const STATUS_COMPLETED = 'completed';
	const STATUS_REVOKED   = 'revoked';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'sglms_user_can_access_course', array( __CLASS__, 'grant_access' ), 10, 3 );
	}

	/**
	 * Fully-qualified enrollments table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sglms_enrollments';
	}

	/**
	 * Gatekeeper integration: enrolled users may access course content.
	 *
	 * @param bool $can       Incoming decision.
	 * @param int  $course_id Course ID.
	 * @param int  $user_id   User ID.
	 * @return bool
	 */
	public static function grant_access( $can, $course_id, $user_id ) {
		if ( $can ) {
			return true;
		}
		if ( ! $user_id ) {
			return false;
		}
		return self::is_enrolled( (int) $user_id, (int) $course_id );
	}

	/* --------------------------------------------------------------------- *
	 * Reads
	 * --------------------------------------------------------------------- */

	/**
	 * Get a user's enrollment status in a course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return string Status, or '' if no record.
	 */
	public static function get_status( $user_id, $course_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM `{$table}` WHERE user_id = %d AND course_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $course_id
			)
		);
		return $status ? (string) $status : '';
	}

	/**
	 * Whether a user is actively enrolled or has completed.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return bool
	 */
	public static function is_enrolled( $user_id, $course_id ) {
		$status = self::get_status( $user_id, $course_id );
		return in_array( $status, array( self::STATUS_ENROLLED, self::STATUS_COMPLETED ), true );
	}

	/**
	 * Get a user's enrollment rows.
	 *
	 * @param int           $user_id  User ID.
	 * @param string[]|null $statuses Optional status filter.
	 * @return array<int,object>
	 */
	public static function get_user_enrollments( $user_id, $statuses = null ) {
		global $wpdb;
		$table = self::table();
		$sql   = "SELECT * FROM `{$table}` WHERE user_id = %d";
		$param = array( (int) $user_id );

		if ( ! empty( $statuses ) ) {
			$statuses     = array_map( 'sanitize_key', (array) $statuses );
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$sql         .= " AND status IN ($placeholders)";
			$param        = array_merge( $param, $statuses );
		}
		$sql .= ' ORDER BY enrolled_at DESC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $param ) );
	}

	/**
	 * Get all enrollment rows for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return array<int,object>
	 */
	public static function get_course_enrollments( $course_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE course_id = %d ORDER BY enrolled_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $course_id
			)
		);
	}

	/**
	 * Count active (enrolled + completed) enrollments for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	public static function count_active( $course_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE course_id = %d AND status IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $course_id,
				self::STATUS_ENROLLED,
				self::STATUS_COMPLETED
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Writes
	 * --------------------------------------------------------------------- */

	/**
	 * Enroll a user in a course (idempotent).
	 *
	 * Re-enrolling a revoked user reactivates them. Already-active or
	 * completed users are left as-is.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param string $source    Enrollment source (manual|self|woo|...).
	 * @return bool True on enroll/reactivate.
	 */
	public static function enroll( $user_id, $course_id, $source = 'manual' ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;
		if ( ! $user_id || 'sglms_course' !== get_post_type( $course_id ) ) {
			return false;
		}

		$status = self::get_status( $user_id, $course_id );
		$table  = self::table();

		if ( self::STATUS_ENROLLED === $status || self::STATUS_COMPLETED === $status ) {
			return true; // Already active.
		}

		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'user_id'     => $user_id,
					'course_id'   => $course_id,
					'status'      => self::STATUS_ENROLLED,
					'source'      => sanitize_key( $source ),
					'enrolled_at' => self::now(),
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
		} else {
			// Reactivate a revoked enrollment.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'      => self::STATUS_ENROLLED,
					'source'      => sanitize_key( $source ),
					'enrolled_at' => self::now(),
				),
				array(
					'user_id'   => $user_id,
					'course_id' => $course_id,
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);
		}

		/**
		 * Fires when a user is enrolled.
		 *
		 * @param int    $user_id   User ID.
		 * @param int    $course_id Course ID.
		 * @param string $source    Source.
		 */
		do_action( 'sglms_user_enrolled', $user_id, $course_id, $source );
		return true;
	}

	/**
	 * Self-enroll the current/given user in a FREE course.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID (defaults to current).
	 * @return true|WP_Error
	 */
	public static function self_enroll( $course_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'sglms_not_logged_in', __( 'You must be logged in to enroll.', 'sglms' ), array( 'status' => 401 ) );
		}
		if ( 'sglms_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'sglms_no_course', __( 'Course not found.', 'sglms' ), array( 'status' => 404 ) );
		}

		$price_mode = get_post_meta( $course_id, '_sglms_price_mode', true );
		$price_mode = $price_mode ? $price_mode : 'free';
		if ( 'free' !== $price_mode ) {
			return new WP_Error( 'sglms_not_free', __( 'This course is not open for free self-enrollment.', 'sglms' ), array( 'status' => 403 ) );
		}

		self::enroll( $user_id, (int) $course_id, 'self' );
		return true;
	}

	/**
	 * Mark an enrollment completed.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public static function complete( $user_id, $course_id ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;

		if ( self::STATUS_COMPLETED === self::get_status( $user_id, $course_id ) ) {
			return;
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'       => self::STATUS_COMPLETED,
				'completed_at' => self::now(),
			),
			array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);

		/**
		 * Fires when a user completes a course (certificate issuance hooks here in Phase 6).
		 *
		 * @param int $user_id   User ID.
		 * @param int $course_id Course ID.
		 */
		do_action( 'sglms_course_completed', $user_id, $course_id );
	}

	/**
	 * Revoke an enrollment.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public static function revoke( $user_id, $course_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'status' => self::STATUS_REVOKED ),
			array(
				'user_id'   => (int) $user_id,
				'course_id' => (int) $course_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		/**
		 * Fires when an enrollment is revoked.
		 *
		 * @param int $user_id   User ID.
		 * @param int $course_id Course ID.
		 */
		do_action( 'sglms_enrollment_revoked', (int) $user_id, (int) $course_id );
	}

	/**
	 * Enrollment timestamp (GMT) for a user/course, or '' if none.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return string MySQL datetime in GMT.
	 */
	public static function enrolled_at( $user_id, $course_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT enrolled_at FROM `{$table}` WHERE user_id = %d AND course_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $course_id
			)
		);
		return $ts ? (string) $ts : '';
	}

	/**
	 * Current GMT datetime.
	 *
	 * @return string
	 */
	private static function now() {
		return current_time( 'mysql', true );
	}
}
