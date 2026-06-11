<?php
/**
 * Custom post types and taxonomies.
 *
 * Courses, lessons, quizzes, and certificate templates plus their
 * taxonomies. Gating of lessons/quizzes (preventing public leakage) is the
 * Gatekeeper's job in Phase 2; here they are registered public so their
 * permalinks, archives, and the block editor work.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Post_Types
 */
class Sglms_Post_Types {

	/**
	 * Top-level admin menu slug that LMS CPTs nest under.
	 */
	const MENU_SLUG = 'stemageddon-lms';

	/**
	 * Hook registration onto init.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_types' ), 5 );
		add_action( 'init', array( $this, 'register_taxonomies' ), 6 );
		add_action( 'init', array( $this, 'maybe_seed_difficulty_terms' ), 7 );
	}

	/**
	 * Register all LMS post types.
	 *
	 * @return void
	 */
	public function register_post_types() {
		register_post_type(
			'sglms_course',
			array(
				'labels'          => $this->labels( __( 'Course', 'sglms' ), __( 'Courses', 'sglms' ) ),
				'public'          => true,
				'has_archive'     => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-welcome-learn-more',
				'show_in_menu'    => self::MENU_SLUG,
				'rewrite'         => array(
					'slug'       => 'courses',
					'with_front' => false,
				),
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields' ),
				'taxonomies'      => array( 'sglms_course_cat', 'sglms_course_tag', 'sglms_difficulty' ),
				'map_meta_cap'    => true,
				'capability_type' => 'post',
			)
		);

		register_post_type(
			'sglms_lesson',
			array(
				'labels'          => $this->labels( __( 'Lesson', 'sglms' ), __( 'Lessons', 'sglms' ) ),
				'public'          => true, // Gated in Phase 2.
				'show_in_rest'    => true,
				'show_in_menu'    => self::MENU_SLUG,
				'rewrite'         => array(
					'slug'       => 'lessons',
					'with_front' => false,
				),
				// page-attributes gives the menu_order field used for lesson ordering;
				// comments power the per-lesson discussion (scoped to enrollees).
				'supports'        => array( 'title', 'editor', 'thumbnail', 'author', 'custom-fields', 'page-attributes', 'comments' ),
				'map_meta_cap'    => true,
				'capability_type' => 'post',
			)
		);

		register_post_type(
			'sglms_quiz',
			array(
				'labels'          => $this->labels( __( 'Quiz', 'sglms' ), __( 'Quizzes', 'sglms' ) ),
				'public'          => true, // Gated in Phase 2.
				'show_in_rest'    => true,
				'show_in_menu'    => self::MENU_SLUG,
				'rewrite'         => array(
					'slug'       => 'quizzes',
					'with_front' => false,
				),
				'supports'        => array( 'title', 'editor', 'author', 'custom-fields' ),
				'map_meta_cap'    => true,
				'capability_type' => 'post',
			)
		);

		// NOTE: WordPress caps post type names at 20 chars, so the plan's
		// "sglms_certificate_tpl" (21) is shortened to "sglms_cert_tpl".
		register_post_type(
			'sglms_cert_tpl',
			array(
				'labels'          => $this->labels( __( 'Certificate Template', 'sglms' ), __( 'Certificate Templates', 'sglms' ) ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => false,
				'show_in_menu'    => self::MENU_SLUG,
				'supports'        => array( 'title', 'thumbnail', 'custom-fields' ),
				'map_meta_cap'    => true,
				'capability_type' => 'post',
			)
		);
	}

	/**
	 * Register taxonomies.
	 *
	 * @return void
	 */
	public function register_taxonomies() {
		register_taxonomy(
			'sglms_course_cat',
			'sglms_course',
			array(
				'labels'            => $this->labels( __( 'Course Category', 'sglms' ), __( 'Course Categories', 'sglms' ) ),
				'hierarchical'      => true,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'course-category' ),
			)
		);

		register_taxonomy(
			'sglms_course_tag',
			'sglms_course',
			array(
				'labels'            => $this->labels( __( 'Course Tag', 'sglms' ), __( 'Course Tags', 'sglms' ) ),
				'hierarchical'      => false,
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'course-tag' ),
			)
		);

		register_taxonomy(
			'sglms_difficulty',
			'sglms_course',
			array(
				'labels'            => $this->labels( __( 'Difficulty', 'sglms' ), __( 'Difficulty Levels', 'sglms' ) ),
				'hierarchical'      => true, // Behaves like a checkbox/select in the editor.
				'public'            => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'difficulty' ),
			)
		);
	}

	/**
	 * Seed the three default difficulty terms once.
	 *
	 * Runs on init (after the taxonomy is registered) rather than on
	 * activation, because wp_insert_term needs the taxonomy to exist. Guarded
	 * by an option so it writes only once.
	 *
	 * @return void
	 */
	public function maybe_seed_difficulty_terms() {
		if ( get_option( 'sglms_difficulty_seeded' ) ) {
			return;
		}

		$terms = array(
			'Beginner'     => __( 'Beginner', 'sglms' ),
			'Intermediate' => __( 'Intermediate', 'sglms' ),
			'Advanced'     => __( 'Advanced', 'sglms' ),
		);

		foreach ( $terms as $slug => $name ) {
			if ( ! term_exists( $name, 'sglms_difficulty' ) ) {
				wp_insert_term( $name, 'sglms_difficulty', array( 'slug' => sanitize_title( $slug ) ) );
			}
		}

		update_option( 'sglms_difficulty_seeded', 1 );
	}

	/**
	 * Build a standard labels array from singular/plural names.
	 *
	 * @param string $singular Singular label.
	 * @param string $plural   Plural label.
	 * @return array<string,string>
	 */
	private function labels( $singular, $plural ) {
		return array(
			'name'                => $plural,
			'singular_name'       => $singular,
			'menu_name'           => $plural,
			'add_new'             => __( 'Add New', 'sglms' ),
			/* translators: %s: singular post type name. */
			'add_new_item'        => sprintf( __( 'Add New %s', 'sglms' ), $singular ),
			/* translators: %s: singular post type name. */
			'edit_item'           => sprintf( __( 'Edit %s', 'sglms' ), $singular ),
			/* translators: %s: singular post type name. */
			'new_item'            => sprintf( __( 'New %s', 'sglms' ), $singular ),
			/* translators: %s: singular post type name. */
			'view_item'           => sprintf( __( 'View %s', 'sglms' ), $singular ),
			/* translators: %s: plural post type name. */
			'search_items'        => sprintf( __( 'Search %s', 'sglms' ), $plural ),
			/* translators: %s: plural post type name. */
			'not_found'           => sprintf( __( 'No %s found', 'sglms' ), $plural ),
			/* translators: %s: plural post type name. */
			'not_found_in_trash'  => sprintf( __( 'No %s found in Trash', 'sglms' ), $plural ),
			'all_items'           => $plural,
			/* translators: %s: plural post type name. */
			'add_or_remove_items' => sprintf( __( 'Add or remove %s', 'sglms' ), $plural ),
		);
	}
}
