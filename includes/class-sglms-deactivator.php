<?php
/**
 * Deactivation routines.
 *
 * Deactivation is non-destructive: it never drops tables, removes roles, or
 * deletes pages. Those are uninstall-only, and only when the admin opts in.
 * Here we just flush rewrite rules so gated permalinks stop resolving.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Deactivator
 */
class Sglms_Deactivator {

	/**
	 * Run on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
