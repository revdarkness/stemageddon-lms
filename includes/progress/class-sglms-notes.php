<?php
/**
 * Per-user private lesson notes.
 *
 * One note per user per lesson, stored in the sglms_notes table. Content is
 * sanitized on save; only the owning user ever reads or writes their note.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Notes
 */
class Sglms_Notes {

	/**
	 * Notes table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sglms_notes';
	}

	/**
	 * Get a user's note for a lesson.
	 *
	 * @param int $user_id   User ID.
	 * @param int $lesson_id Lesson ID.
	 * @return string
	 */
	public static function get( $user_id, $lesson_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$content = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content FROM `{$table}` WHERE user_id = %d AND lesson_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $lesson_id
			)
		);
		return $content ? (string) $content : '';
	}

	/**
	 * Save (upsert) a user's note for a lesson.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $lesson_id Lesson ID.
	 * @param string $content   Raw note content.
	 * @return string The stored (sanitized) content.
	 */
	public static function save( $user_id, $lesson_id, $content ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$lesson_id = (int) $lesson_id;
		$content   = wp_kses_post( (string) $content );
		$now       = current_time( 'mysql', true );
		$table     = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$table}` WHERE user_id = %d AND lesson_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$lesson_id
			)
		);

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'content'    => $content,
					'updated_at' => $now,
				),
				array( 'id' => (int) $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'user_id'    => $user_id,
					'lesson_id'  => $lesson_id,
					'content'    => $content,
					'updated_at' => $now,
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}

		return $content;
	}
}
