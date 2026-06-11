<?php
/**
 * Admin menu shell.
 *
 * Registers the top-level "STEMageddon LMS" menu. Course, Quiz, and
 * Certificate Template CPTs nest under it automatically via their
 * show_in_menu setting; this class adds the overview screen plus the
 * Enrollments, Reports, and Settings placeholder pages that later phases
 * fill in.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Admin_Menu
 */
class Sglms_Admin_Menu {

	/**
	 * Hook onto admin_menu.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	/**
	 * Register the menu tree.
	 *
	 * @return void
	 */
	public function register() {
		// Both administrators and instructors hold this cap, so both see the
		// LMS menu; per-screen caps below further restrict Reports/Settings.
		$cap = 'sglms_manage_own_courses';

		add_menu_page(
			__( 'STEMageddon LMS', 'sglms' ),
			__( 'STEMageddon LMS', 'sglms' ),
			$cap,
			Sglms_Post_Types::MENU_SLUG,
			array( $this, 'render_overview' ),
			'dashicons-welcome-learn-more',
			26
		);

		// First submenu mirrors the top-level item; rename it "Overview".
		add_submenu_page(
			Sglms_Post_Types::MENU_SLUG,
			__( 'LMS Overview', 'sglms' ),
			__( 'Overview', 'sglms' ),
			$cap,
			Sglms_Post_Types::MENU_SLUG,
			array( $this, 'render_overview' )
		);

		// CPT screens (Courses, Quizzes, Certificate Templates) auto-insert
		// here from their show_in_menu setting. The Enrollments, Reports, and
		// Settings submenus are registered by their own classes.
	}

	/**
	 * Overview screen: a quick status panel.
	 *
	 * @return void
	 */
	public function render_overview() {
		$counts = array(
			'courses' => (int) wp_count_posts( 'sglms_course' )->publish,
			'lessons' => (int) wp_count_posts( 'sglms_lesson' )->publish,
			'quizzes' => (int) wp_count_posts( 'sglms_quiz' )->publish,
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'STEMageddon LMS', 'sglms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Self-contained learning management. Build courses, track progress, issue certificates.', 'sglms' ) . '</p>';

		echo '<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">';
		foreach ( array(
			__( 'Published Courses', 'sglms' ) => $counts['courses'],
			__( 'Lessons', 'sglms' )           => $counts['lessons'],
			__( 'Quizzes', 'sglms' )           => $counts['quizzes'],
		) as $label => $value ) {
			echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 24px;min-width:140px;">';
			echo '<div style="font-size:28px;font-weight:600;">' . esc_html( number_format_i18n( $value ) ) . '</div>';
			echo '<div style="color:#646970;">' . esc_html( $label ) . '</div>';
			echo '</div>';
		}
		echo '</div>';

		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( admin_url( 'post-new.php?post_type=sglms_course' ) ),
			esc_html__( 'Create a Course', 'sglms' )
		);
		echo '</div>';
	}
}
