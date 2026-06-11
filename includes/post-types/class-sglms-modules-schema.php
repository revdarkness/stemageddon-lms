<?php
/**
 * Course modules data layer and strict schema validation.
 *
 * A course's curriculum structure lives in the `_sglms_modules` meta as a
 * JSON array of module objects:
 *
 *   [
 *     { "id": "mod_ab12cd", "title": "Getting Started", "lessons": [12, 15] },
 *     { "id": "mod_ef34gh", "title": "Build Phase",     "lessons": [20] }
 *   ]
 *
 * Per the security checklist, builder payloads are never trusted: every save
 * runs through sanitize(), which coerces the input to exactly this shape and
 * discards anything else. Lesson IDs are validated to be real lesson posts.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Modules_Schema
 */
class Sglms_Modules_Schema {

	/**
	 * Meta key for the modules structure.
	 */
	const META_KEY = '_sglms_modules';

	/**
	 * Get a course's modules as a normalized array.
	 *
	 * @param int $course_id Course post ID.
	 * @return array<int,array{id:string,title:string,lessons:int[]}>
	 */
	public static function get( $course_id ) {
		$raw = get_post_meta( (int) $course_id, self::META_KEY, true );
		if ( empty( $raw ) ) {
			return array();
		}
		return self::sanitize( $raw );
	}

	/**
	 * Validate and persist a course's modules.
	 *
	 * @param int          $course_id Course post ID.
	 * @param array|string $raw       Raw modules payload (array or JSON string).
	 * @return array The sanitized structure that was stored.
	 */
	public static function save( $course_id, $raw ) {
		$clean = self::sanitize( $raw );
		update_post_meta( (int) $course_id, self::META_KEY, wp_json_encode( $clean ) );
		return $clean;
	}

	/**
	 * Coerce any input into the strict modules schema.
	 *
	 * Accepts a JSON string or an array. Returns a clean, re-indexed array.
	 * Unknown keys are dropped; malformed entries are skipped.
	 *
	 * @param array|string $raw Raw payload.
	 * @return array<int,array{id:string,title:string,lessons:int[]}>
	 */
	public static function sanitize( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean    = array();
		$seen_ids = array();

		foreach ( $raw as $module ) {
			if ( ! is_array( $module ) ) {
				continue;
			}

			// Module ID: keep a provided slug-safe id, else generate one.
			$id = isset( $module['id'] ) ? sanitize_key( (string) $module['id'] ) : '';
			if ( '' === $id || isset( $seen_ids[ $id ] ) ) {
				$id = self::generate_module_id();
			}
			$seen_ids[ $id ] = true;

			$title = isset( $module['title'] ) ? sanitize_text_field( (string) $module['title'] ) : '';

			$lessons = array();
			if ( isset( $module['lessons'] ) && is_array( $module['lessons'] ) ) {
				foreach ( $module['lessons'] as $lesson_id ) {
					$lesson_id = absint( $lesson_id );
					if ( $lesson_id && 'sglms_lesson' === get_post_type( $lesson_id ) && ! in_array( $lesson_id, $lessons, true ) ) {
						$lessons[] = $lesson_id;
					}
				}
			}

			$clean[] = array(
				'id'      => $id,
				'title'   => $title,
				'lessons' => $lessons,
			);
		}

		return $clean;
	}

	/**
	 * Flatten a course's modules to an ordered list of lesson IDs.
	 *
	 * @param int $course_id Course post ID.
	 * @return int[] Lesson IDs in curriculum order.
	 */
	public static function ordered_lesson_ids( $course_id ) {
		$ids = array();
		foreach ( self::get( $course_id ) as $module ) {
			foreach ( $module['lessons'] as $lesson_id ) {
				$ids[] = $lesson_id;
			}
		}
		return $ids;
	}

	/**
	 * Generate a collision-resistant module id.
	 *
	 * @return string
	 */
	private static function generate_module_id() {
		return 'mod_' . strtolower( wp_generate_password( 8, false, false ) );
	}
}
