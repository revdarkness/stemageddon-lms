<?php
/**
 * Activation routines: custom tables, roles/capabilities, required pages,
 * protected upload directory, and a rewrite flush.
 *
 * Every routine here is idempotent so it can run on activation AND on the
 * silent DB-version upgrade path from Sglms_Plugin::maybe_upgrade_db().
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Activator
 */
class Sglms_Activator {

	/**
	 * Full activation sequence.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_tables();
		self::add_roles_and_caps();
		self::create_pages();
		self::create_protected_dir();

		update_option( 'sglms_db_version', SGLMS_DB_VERSION );
		update_option( 'sglms_version', SGLMS_VERSION );

		// Ensure a download-token signing secret exists.
		if ( ! get_option( 'sglms_token_secret' ) ) {
			update_option( 'sglms_token_secret', wp_generate_password( 64, true, true ) );
		}

		// Register CPTs/taxonomies now so their rewrite rules are included in
		// the flush; otherwise their permalinks 404 until the next flush.
		$post_types = new Sglms_Post_Types();
		$post_types->register_post_types();
		$post_types->register_taxonomies();
		// Plugin pretty URLs (certificate verification + protected downloads).
		Sglms_Cert_Verify::register_rule();
		Sglms_Downloads::register_rule();
		flush_rewrite_rules();
		// Keep the rewrite-version marker in step so init does not re-flush.
		update_option( 'sglms_rewrite_version', SGLMS_VERSION );
	}

	/**
	 * Create or upgrade custom tables via dbDelta.
	 *
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'sglms_';

		$schemas = array();

		$schemas[] = "CREATE TABLE {$prefix}enrollments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'enrolled',
			source VARCHAR(40) NOT NULL DEFAULT 'manual',
			enrolled_at DATETIME NOT NULL,
			completed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_course (user_id, course_id),
			KEY course_id (course_id),
			KEY status (status)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$prefix}progress (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'incomplete',
			completed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_lesson (user_id, lesson_id),
			KEY user_course (user_id, course_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$prefix}quiz_attempts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			quiz_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			score DECIMAL(7,2) NOT NULL DEFAULT 0,
			max_score DECIMAL(7,2) NOT NULL DEFAULT 0,
			passed TINYINT(1) NOT NULL DEFAULT 0,
			answers_json LONGTEXT NULL,
			started_at DATETIME NOT NULL,
			finished_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_quiz (user_id, quiz_id),
			KEY course_id (course_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$prefix}certificates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			template_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cert_uid CHAR(20) NOT NULL,
			pdf_path VARCHAR(255) DEFAULT NULL,
			revoked TINYINT(1) NOT NULL DEFAULT 0,
			issued_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cert_uid (cert_uid),
			KEY user_course (user_id, course_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$prefix}downloads_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			course_id BIGINT UNSIGNED NOT NULL,
			ip_hash CHAR(64) DEFAULT NULL,
			downloaded_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY course_id (course_id),
			KEY user_id (user_id)
		) $charset_collate;";

		$schemas[] = "CREATE TABLE {$prefix}notes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			content LONGTEXT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_lesson (user_id, lesson_id)
		) $charset_collate;";

		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Register custom roles and map LMS capabilities.
	 *
	 * @return void
	 */
	public static function add_roles_and_caps() {
		$lms_caps = self::lms_capabilities();

		// Create roles if missing (add_role is a no-op when they exist).
		add_role( 'sglms_student', __( 'LMS Student', 'sglms' ), array( 'read' => true ) );
		add_role( 'sglms_instructor', __( 'LMS Instructor', 'sglms' ), array( 'read' => true ) );

		// Apply caps every run so upgrades pick up changes (add_role won't).
		$student = get_role( 'sglms_student' );
		if ( $student ) {
			$student->add_cap( 'read' );
		}

		// Instructor: full control of their OWN content (no *_others_posts),
		// so the map_meta_cap filter confines them to their own courses.
		$instructor = get_role( 'sglms_instructor' );
		if ( $instructor ) {
			foreach ( array(
				'read',
				'upload_files',
				'edit_posts',
				'edit_published_posts',
				'publish_posts',
				'delete_posts',
				'delete_published_posts',
				'sglms_manage_own_courses',
			) as $cap ) {
				$instructor->add_cap( $cap );
			}
		}

		// Administrators get the full LMS capability set.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $lms_caps as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * The canonical list of LMS capabilities.
	 *
	 * @return string[]
	 */
	public static function lms_capabilities() {
		return array(
			'sglms_manage_courses',
			'sglms_manage_own_courses',
			'sglms_view_reports',
			'sglms_issue_certificates',
		);
	}

	/**
	 * Create the required front-end pages and store their IDs in options.
	 * Only creates a page if its option is unset or points to a missing post.
	 *
	 * @return void
	 */
	public static function create_pages() {
		$pages = array(
			'sglms_page_dashboard' => array(
				'title'   => __( 'My Learning', 'sglms' ),
				'content' => '<!-- wp:shortcode -->[sglms_dashboard]<!-- /wp:shortcode -->',
			),
			'sglms_page_catalog'   => array(
				'title'   => __( 'Course Catalog', 'sglms' ),
				'content' => '<!-- wp:shortcode -->[sglms_catalog]<!-- /wp:shortcode -->',
			),
			'sglms_page_denied'    => array(
				'title'   => __( 'Access Denied', 'sglms' ),
				'content' => '<!-- wp:shortcode -->[sglms_access_denied]<!-- /wp:shortcode -->',
			),
		);

		foreach ( $pages as $option_key => $page ) {
			$existing = (int) get_option( $option_key );
			if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( $option_key, (int) $page_id );
			}
		}
	}

	/**
	 * Create the protected uploads directory with hardening files.
	 *
	 * @return void
	 */
	public static function create_protected_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'sglms-protected';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Blank index to defeat directory listing.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// Apache deny rule; nginx users get instructions in readme.txt.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# STEMageddon LMS: deny direct access. Files are served through the tokenized download controller.\n";
			$rules .= "Order Allow,Deny\nDeny from all\n";
			$rules .= "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
