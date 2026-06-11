<?php
/**
 * Capability enforcement.
 *
 * Instructors manage only their OWN LMS content. The instructor role is
 * granted own-content caps (no *_others_posts), and this filter adds a
 * belt-and-suspenders check: editing or deleting another author's LMS post
 * is denied unless the user holds sglms_manage_courses (administrators).
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Roles
 */
class Sglms_Roles {

	/**
	 * LMS post types subject to ownership enforcement.
	 */
	const TYPES = array( 'sglms_course', 'sglms_lesson', 'sglms_quiz', 'sglms_cert_tpl' );

	/**
	 * Hook the capability filter.
	 */
	public function __construct() {
		add_filter( 'map_meta_cap', array( $this, 'restrict_to_own' ), 10, 4 );
	}

	/**
	 * Deny edit/delete of another author's LMS content for non-managers.
	 *
	 * @param string[] $caps    Required primitive caps.
	 * @param string   $cap     Meta cap being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Args; $args[0] is the post ID.
	 * @return string[]
	 */
	public function restrict_to_own( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $post_id ) {
			return $caps;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::TYPES, true ) ) {
			return $caps;
		}

		// Managers (administrators) are exempt.
		if ( user_can( $user_id, 'sglms_manage_courses' ) ) {
			return $caps;
		}

		// Non-owners cannot touch someone else's LMS content.
		if ( (int) $post->post_author !== $user_id ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}
}
