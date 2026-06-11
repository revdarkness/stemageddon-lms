<?php
/**
 * Reports screen (LMS → Reports) with CSV export.
 *
 * Summary metrics, per-course completion rates, and quiz score stats, plus a
 * CSV download for each dataset. Read access is gated by sglms_view_reports.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Reports
 */
class Sglms_Reports {

	const SLUG = 'sglms-reports';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_post_sglms_export_report', array( $this, 'export' ) );
	}

	/**
	 * Register the submenu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			Sglms_Post_Types::MENU_SLUG,
			__( 'Reports', 'sglms' ),
			__( 'Reports', 'sglms' ),
			'sglms_view_reports',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Data
	 * --------------------------------------------------------------------- */

	/**
	 * Headline totals.
	 *
	 * @return array<string,int>
	 */
	public static function totals() {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'enrollments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sglms_enrollments WHERE status IN ('enrolled','completed')" ),
			'completions'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sglms_enrollments WHERE status = 'completed'" ),
			'certificates'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sglms_certificates WHERE revoked = 0" ),
			'quiz_attempts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sglms_quiz_attempts" ),
			'downloads'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}sglms_downloads_log" ),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Per-course enrollment / completion rows.
	 *
	 * @return array<int,array>
	 */
	public static function per_course() {
		$rows    = array();
		$courses = get_posts(
			array(
				'post_type'   => 'sglms_course',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => -1,
			)
		);
		foreach ( $courses as $c ) {
			$active    = Sglms_Enrollment::count_active( $c->ID );
			$completed = self::completed_count( $c->ID );
			$rows[]    = array(
				'course'    => $c->post_title,
				'enrolled'  => $active,
				'completed' => $completed,
				'rate'      => $active > 0 ? round( $completed / $active * 100 ) : 0,
			);
		}
		return $rows;
	}

	/**
	 * Completed enrollment count for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	private static function completed_count( $course_id ) {
		global $wpdb;
		$t = $wpdb->prefix . 'sglms_enrollments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE course_id = %d AND status = 'completed'", (int) $course_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Per-quiz stats rows.
	 *
	 * @return array<int,array>
	 */
	public static function per_quiz() {
		$rows    = array();
		$quizzes = get_posts(
			array(
				'post_type'   => 'sglms_quiz',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => -1,
			)
		);
		foreach ( $quizzes as $q ) {
			$s      = Sglms_Quiz::summary( $q->ID );
			$rows[] = array(
				'quiz'     => $q->post_title,
				'attempts' => $s['attempts'],
				'learners' => $s['users'],
				'passed'   => $s['passed'],
				'avg'      => $s['avg'],
			);
		}
		return $rows;
	}

	/* --------------------------------------------------------------------- *
	 * Render
	 * --------------------------------------------------------------------- */

	/**
	 * Render the reports page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'sglms_view_reports' ) ) {
			return;
		}
		$totals = self::totals();

		echo '<div class="wrap"><h1>' . esc_html__( 'Reports', 'sglms' ) . '</h1>';

		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0;">';
		foreach ( array(
			__( 'Active enrollments', 'sglms' ) => $totals['enrollments'],
			__( 'Completions', 'sglms' )        => $totals['completions'],
			__( 'Certificates', 'sglms' )       => $totals['certificates'],
			__( 'Quiz attempts', 'sglms' )      => $totals['quiz_attempts'],
			__( 'Downloads', 'sglms' )          => $totals['downloads'],
		) as $label => $val ) {
			echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px 20px;min-width:120px;">';
			echo '<div style="font-size:26px;font-weight:600;">' . esc_html( number_format_i18n( $val ) ) . '</div>';
			echo '<div style="color:#646970;">' . esc_html( $label ) . '</div></div>';
		}
		echo '</div>';

		// Per-course.
		// export_link() returns markup escaped internally (esc_url + esc_html).
		echo '<h2>' . esc_html__( 'Courses', 'sglms' ) . ' ' . $this->export_link( 'courses' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Course', 'sglms' ) . '</th><th>' . esc_html__( 'Enrolled', 'sglms' ) . '</th><th>' . esc_html__( 'Completed', 'sglms' ) . '</th><th>' . esc_html__( 'Rate', 'sglms' ) . '</th></tr></thead><tbody>';
		foreach ( self::per_course() as $r ) {
			echo '<tr><td>' . esc_html( $r['course'] ) . '</td><td>' . esc_html( $r['enrolled'] ) . '</td><td>' . esc_html( $r['completed'] ) . '</td><td>' . esc_html( $r['rate'] ) . '%</td></tr>';
		}
		echo '</tbody></table>';

		// Per-quiz.
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Quizzes', 'sglms' ) . ' ' . $this->export_link( 'quizzes' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Quiz', 'sglms' ) . '</th><th>' . esc_html__( 'Attempts', 'sglms' ) . '</th><th>' . esc_html__( 'Learners', 'sglms' ) . '</th><th>' . esc_html__( 'Passed', 'sglms' ) . '</th><th>' . esc_html__( 'Avg', 'sglms' ) . '</th></tr></thead><tbody>';
		foreach ( self::per_quiz() as $r ) {
			echo '<tr><td>' . esc_html( $r['quiz'] ) . '</td><td>' . esc_html( $r['attempts'] ) . '</td><td>' . esc_html( $r['learners'] ) . '</td><td>' . esc_html( $r['passed'] ) . '</td><td>' . esc_html( $r['avg'] ) . '%</td></tr>';
		}
		echo '</tbody></table>';

		echo '</div>';
	}

	/**
	 * A CSV-export link/button for a report type.
	 *
	 * @param string $type Report type.
	 * @return string
	 */
	private function export_link( $type ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=sglms_export_report&report=' . rawurlencode( $type ) ),
			'sglms_export_report'
		);
		return '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Export CSV', 'sglms' ) . '</a>';
	}

	/* --------------------------------------------------------------------- *
	 * CSV export
	 * --------------------------------------------------------------------- */

	/**
	 * Handle a CSV export request.
	 *
	 * @return void
	 */
	public function export() {
		if ( ! current_user_can( 'sglms_view_reports' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'sglms' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'sglms_export_report' );

		$type = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';

		if ( 'quizzes' === $type ) {
			$header = array( 'Quiz', 'Attempts', 'Learners', 'Passed', 'Avg %' );
			$rows   = array_map(
				static function ( $r ) {
					return array( $r['quiz'], $r['attempts'], $r['learners'], $r['passed'], $r['avg'] );
				},
				self::per_quiz()
			);
			$file   = 'sglms-quizzes.csv';
		} else {
			$header = array( 'Course', 'Enrolled', 'Completed', 'Completion %' );
			$rows   = array_map(
				static function ( $r ) {
					return array( $r['course'], $r['enrolled'], $r['completed'], $r['rate'] );
				},
				self::per_course()
			);
			$file   = 'sglms-courses.csv';
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $header );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}
}
