<?php
/**
 * Uninstall handler.
 *
 * Fires only when the admin deletes the plugin from the WordPress admin.
 * Destructive removal is gated behind the 'sglms_delete_data_on_uninstall'
 * option: if the admin never opted in, we leave all data untouched so an
 * accidental delete/reinstall does not wipe student records.
 *
 * All logic is wrapped in a function so its variables stay out of the global
 * scope.
 *
 * @package Sglms
 */

// Exit if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all STEMageddon LMS data.
 *
 * @return void
 */
function sglms_run_uninstall() {
	global $wpdb;

	// Respect the opt-in. Default: keep everything.
	if ( ! get_option( 'sglms_delete_data_on_uninstall' ) ) {
		return;
	}

	// 1. Drop custom tables.
	$tables = array(
		'sglms_enrollments',
		'sglms_progress',
		'sglms_quiz_attempts',
		'sglms_certificates',
		'sglms_downloads_log',
		'sglms_notes',
	);
	foreach ( $tables as $table ) {
		$full = $wpdb->prefix . $table;
		// Table identifiers cannot be parameterized; built from the known whitelist above.
		$wpdb->query( "DROP TABLE IF EXISTS `{$full}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	// 2. Delete options.
	$options = array(
		'sglms_db_version',
		'sglms_version',
		'sglms_token_secret',
		'sglms_page_dashboard',
		'sglms_page_catalog',
		'sglms_page_denied',
		'sglms_settings',
		'sglms_download_whitelist',
		'sglms_difficulty_seeded',
		'sglms_rewrite_version',
		'sglms_delete_data_on_uninstall',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// 3. Delete LMS pages.
	foreach ( array( 'sglms_page_dashboard', 'sglms_page_catalog', 'sglms_page_denied' ) as $page_option ) {
		$page_id = (int) get_option( $page_option );
		if ( $page_id ) {
			wp_delete_post( $page_id, true );
		}
	}

	// 4. Remove custom roles and stripped LMS caps from administrator.
	remove_role( 'sglms_student' );
	remove_role( 'sglms_instructor' );

	$admin_role = get_role( 'administrator' );
	if ( $admin_role ) {
		foreach ( array( 'sglms_manage_courses', 'sglms_manage_own_courses', 'sglms_view_reports', 'sglms_issue_certificates' ) as $cap ) {
			$admin_role->remove_cap( $cap );
		}
	}

	// 5. Delete LMS custom post type content.
	foreach ( array( 'sglms_course', 'sglms_lesson', 'sglms_quiz', 'sglms_cert_tpl' ) as $cpt ) {
		$cpt_posts = get_posts(
			array(
				'post_type'   => $cpt,
				'numberposts' => -1,
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);
		foreach ( $cpt_posts as $cpt_post_id ) {
			wp_delete_post( $cpt_post_id, true );
		}
	}

	// 6. Remove the protected uploads directory (best effort, recursive).
	$uploads = wp_upload_dir();
	if ( empty( $uploads['error'] ) ) {
		$dir = trailingslashit( $uploads['basedir'] ) . 'sglms-protected';
		if ( is_dir( $dir ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $path ) {
				if ( $path->isDir() ) {
					@rmdir( $path->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				} else {
					@unlink( $path->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
	}
}

sglms_run_uninstall();
