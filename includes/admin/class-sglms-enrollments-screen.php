<?php
/**
 * Admin Enrollments screen.
 *
 * Registers the Enrollments submenu under the LMS menu and provides manual
 * enroll, revoke, and a per-course enrollment roster. Actions are nonce- and
 * capability-checked; an admin must be able to edit the course to manage its
 * enrollments, which keeps instructors scoped to their own courses.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Enrollments_Screen
 */
class Sglms_Enrollments_Screen {

	const SLUG         = 'sglms-enrollments';
	const NONCE_ACTION = 'sglms_manage_enrollment';

	/**
	 * Hook menu registration (after the parent menu exists).
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register' ), 11 );
	}

	/**
	 * Register the submenu.
	 *
	 * @return void
	 */
	public function register() {
		add_submenu_page(
			Sglms_Post_Types::MENU_SLUG,
			__( 'Enrollments', 'sglms' ),
			__( 'Enrollments', 'sglms' ),
			'sglms_manage_own_courses',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen and process any submitted action.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'sglms_manage_own_courses' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage enrollments.', 'sglms' ) );
		}

		$notice = $this->handle_action();

		$selected_course = isset( $_GET['course'] ) ? absint( wp_unslash( $_GET['course'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$courses         = get_posts(
			array(
				'post_type'   => 'sglms_course',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Enrollments', 'sglms' ) . '</h1>';

		if ( $notice ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['msg'] ) );
		}

		$this->render_enroll_form( $courses );
		$this->render_course_filter( $courses, $selected_course );

		if ( $selected_course ) {
			$this->render_roster( $selected_course );
		} else {
			echo '<p>' . esc_html__( 'Select a course to view its roster.', 'sglms' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Process enroll/revoke actions.
	 *
	 * @return array{type:string,msg:string}|null
	 */
	private function handle_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['sglms_action'] ) ? sanitize_key( wp_unslash( $_POST['sglms_action'] ) ) : '';
		if ( ! $action ) {
			return null;
		}
		if ( ! isset( $_POST['_sglms_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_sglms_nonce'] ) ), self::NONCE_ACTION ) ) {
			return array(
				'type' => 'error',
				'msg'  => __( 'Security check failed. Please try again.', 'sglms' ),
			);
		}

		$user_id   = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;

		// The enroll form supplies a login/email lookup instead of an ID.
		if ( ! $user_id && isset( $_POST['user_lookup'] ) ) {
			$lookup = sanitize_text_field( wp_unslash( $_POST['user_lookup'] ) );
			if ( $lookup ) {
				$user = is_email( $lookup ) ? get_user_by( 'email', $lookup ) : get_user_by( 'login', $lookup );
				if ( ! $user && is_numeric( $lookup ) ) {
					$user = get_user_by( 'id', (int) $lookup );
				}
				$user_id = $user ? (int) $user->ID : 0;
			}
		}

		if ( ! $user_id || ! $course_id ) {
			return array(
				'type' => 'error',
				'msg'  => __( 'A user and a course are required.', 'sglms' ),
			);
		}
		// Must be able to edit the course to manage its enrollments.
		if ( ! current_user_can( 'edit_post', $course_id ) ) {
			return array(
				'type' => 'error',
				'msg'  => __( 'You cannot manage enrollments for that course.', 'sglms' ),
			);
		}

		if ( 'enroll' === $action ) {
			if ( ! get_userdata( $user_id ) ) {
				return array(
					'type' => 'error',
					'msg'  => __( 'That user does not exist.', 'sglms' ),
				);
			}
			Sglms_Enrollment::enroll( $user_id, $course_id, 'manual' );
			return array(
				'type' => 'success',
				'msg'  => __( 'User enrolled.', 'sglms' ),
			);
		}

		if ( 'revoke' === $action ) {
			Sglms_Enrollment::revoke( $user_id, $course_id );
			return array(
				'type' => 'success',
				'msg'  => __( 'Enrollment revoked.', 'sglms' ),
			);
		}

		if ( 'cert_issue' === $action ) {
			$result = Sglms_Certificate::issue( $user_id, $course_id );
			if ( is_wp_error( $result ) ) {
				return array(
					'type' => 'error',
					'msg'  => $result->get_error_message(),
				);
			}
			return array(
				'type' => 'success',
				'msg'  => __( 'Certificate issued.', 'sglms' ),
			);
		}

		if ( in_array( $action, array( 'cert_revoke', 'cert_regen' ), true ) ) {
			$cert = Sglms_Certificate::get_active( $user_id, $course_id );
			// For regen, fall back to any cert (active query returns non-revoked).
			$uid = isset( $_POST['cert_uid'] ) ? sanitize_text_field( wp_unslash( $_POST['cert_uid'] ) ) : ( $cert ? $cert->cert_uid : '' );
			if ( ! $uid ) {
				return array(
					'type' => 'error',
					'msg'  => __( 'No certificate to act on.', 'sglms' ),
				);
			}
			if ( 'cert_revoke' === $action ) {
				Sglms_Certificate::revoke( $uid );
				return array(
					'type' => 'success',
					'msg'  => __( 'Certificate revoked.', 'sglms' ),
				);
			}
			$re = Sglms_Certificate::regenerate( $uid );
			return is_wp_error( $re )
				? array(
					'type' => 'error',
					'msg'  => $re->get_error_message(),
				)
				: array(
					'type' => 'success',
					'msg'  => __( 'Certificate regenerated.', 'sglms' ),
				);
		}

		return null;
	}

	/**
	 * Manual enrollment form.
	 *
	 * @param WP_Post[] $courses Courses.
	 * @return void
	 */
	private function render_enroll_form( $courses ) {
		echo '<h2>' . esc_html__( 'Manually enroll a user', 'sglms' ) . '</h2>';
		echo '<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;">';
		wp_nonce_field( self::NONCE_ACTION, '_sglms_nonce' );
		echo '<input type="hidden" name="sglms_action" value="enroll" />';

		echo '<p style="margin:0;"><label>' . esc_html__( 'User (login or email)', 'sglms' ) . '<br />';
		echo '<input type="text" name="user_lookup" list="sglms-users" required style="min-width:240px;" /></label></p>';

		// A lightweight datalist of users (id resolved server-side below).
		echo '<datalist id="sglms-users"></datalist>';

		echo '<p style="margin:0;"><label>' . esc_html__( 'Course', 'sglms' ) . '<br /><select name="course_id" required>';
		echo '<option value="">' . esc_html__( '— Select —', 'sglms' ) . '</option>';
		foreach ( $courses as $c ) {
			if ( current_user_can( 'edit_post', $c->ID ) ) {
				printf( '<option value="%1$d">%2$s</option>', (int) $c->ID, esc_html( $c->post_title ) );
			}
		}
		echo '</select></label></p>';

		// user_lookup is resolved to user_id on submit via a hidden helper.
		echo '<p style="margin:0;"><button type="submit" class="button button-primary">' . esc_html__( 'Enroll', 'sglms' ) . '</button></p>';
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Enter an existing user\'s login or email address.', 'sglms' ) . '</p>';
	}

	/**
	 * Course selector for the roster.
	 *
	 * @param WP_Post[] $courses  Courses.
	 * @param int       $selected Selected course ID.
	 * @return void
	 */
	private function render_course_filter( $courses, $selected ) {
		echo '<h2>' . esc_html__( 'Course roster', 'sglms' ) . '</h2>';
		echo '<form method="get" style="margin-bottom:12px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<select name="course" onchange="this.form.submit()">';
		echo '<option value="0">' . esc_html__( '— Select a course —', 'sglms' ) . '</option>';
		foreach ( $courses as $c ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $c->ID,
				selected( $selected, (int) $c->ID, false ),
				esc_html( $c->post_title )
			);
		}
		echo '</select>';
		echo '</form>';
	}

	/**
	 * Render a small inline certificate-action form button.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param string $action    Action key.
	 * @param string $label     Button label.
	 * @param bool   $danger    Render in delete style.
	 * @return void
	 */
	private function cert_button( $user_id, $course_id, $action, $label, $danger = false ) {
		echo '<form method="post" style="display:inline;margin-right:6px;">';
		wp_nonce_field( self::NONCE_ACTION, '_sglms_nonce' );
		echo '<input type="hidden" name="sglms_action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( $user_id ) . '" />';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( $course_id ) . '" />';
		echo '<button type="submit" class="button-link' . ( $danger ? '-delete' : '' ) . '"' . ( $danger ? ' style="color:#b32d2e;"' : '' ) . '>' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Roster table for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return void
	 */
	private function render_roster( $course_id ) {
		if ( ! current_user_can( 'edit_post', $course_id ) ) {
			echo '<p>' . esc_html__( 'You cannot view that course\'s roster.', 'sglms' ) . '</p>';
			return;
		}

		$rows = Sglms_Enrollment::get_course_enrollments( $course_id );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'User', 'sglms' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'sglms' ) . '</th>';
		echo '<th>' . esc_html__( 'Progress', 'sglms' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'sglms' ) . '</th>';
		echo '<th>' . esc_html__( 'Enrolled', 'sglms' ) . '</th>';
		echo '<th>' . esc_html__( 'Certificate', 'sglms' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No enrollments yet.', 'sglms' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$user     = get_userdata( $row->user_id );
			$progress = Sglms_Progress::get_course_progress( (int) $row->user_id, $course_id );
			echo '<tr>';
			echo '<td>' . esc_html( $user ? $user->user_login : ( '#' . (int) $row->user_id ) ) . '</td>';
			echo '<td>' . esc_html( $row->status ) . '</td>';
			echo '<td>' . esc_html( $progress['percent'] . '% (' . $progress['completed'] . '/' . $progress['total'] . ')' ) . '</td>';
			echo '<td>' . esc_html( $row->source ) . '</td>';
			echo '<td>' . esc_html( $row->enrolled_at ) . '</td>';

			// Certificate column.
			echo '<td>';
			$cert = Sglms_Certificate::get_active( (int) $row->user_id, $course_id );
			if ( $cert ) {
				echo '<a href="' . esc_url( Sglms_Certificate::verify_url( $cert->cert_uid ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Verify', 'sglms' ) . '</a> · ';
				echo '<a href="' . esc_url( Sglms_Certificate::download_url( $cert->cert_uid ) ) . '">' . esc_html__( 'PDF', 'sglms' ) . '</a>';
				echo '<div style="margin-top:4px;">';
				$this->cert_button( $row->user_id, $course_id, 'cert_regen', __( 'Regenerate', 'sglms' ) );
				$this->cert_button( $row->user_id, $course_id, 'cert_revoke', __( 'Revoke cert', 'sglms' ), true );
				echo '</div>';
			} elseif ( Sglms_Enrollment::STATUS_COMPLETED === $row->status ) {
				$this->cert_button( $row->user_id, $course_id, 'cert_issue', __( 'Issue', 'sglms' ) );
			} else {
				echo '—';
			}
			echo '</td>';

			echo '<td>';
			if ( Sglms_Enrollment::STATUS_REVOKED !== $row->status ) {
				echo '<form method="post" style="margin:0;">';
				wp_nonce_field( self::NONCE_ACTION, '_sglms_nonce' );
				echo '<input type="hidden" name="sglms_action" value="revoke" />';
				echo '<input type="hidden" name="user_id" value="' . esc_attr( $row->user_id ) . '" />';
				echo '<input type="hidden" name="course_id" value="' . esc_attr( $course_id ) . '" />';
				echo '<button type="submit" class="button-link-delete" style="color:#b32d2e;">' . esc_html__( 'Revoke', 'sglms' ) . '</button>';
				echo '</form>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
