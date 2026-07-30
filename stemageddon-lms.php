<?php
/**
 * Plugin Name:       STEMageddon LMS
 * Plugin URI:        https://github.com/revdarkness/stemageddon-lms
 * Description:        Self-contained, Coursera-style LMS for WordPress. Courses, quizzes, certificates, protected downloads, and membership access control with zero paid dependencies.
 * Version:           1.0.3
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            STEMageddon Labs
 * Author URI:        https://www.stemageddon.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sglms
 * Domain Path:       /languages
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/*
 * -------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------
 */
define( 'SGLMS_VERSION', '1.0.3' );
define( 'SGLMS_DB_VERSION', '1' );
define( 'SGLMS_FILE', __FILE__ );
define( 'SGLMS_BASENAME', plugin_basename( __FILE__ ) );
define( 'SGLMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SGLMS_URL', plugin_dir_url( __FILE__ ) );
define( 'SGLMS_INCLUDES', SGLMS_PATH . 'includes/' );
define( 'SGLMS_TEMPLATES', SGLMS_PATH . 'templates/' );

/*
 * -------------------------------------------------------------------------
 * Autoloader
 * -------------------------------------------------------------------------
 *
 * Maps class names to files using the WordPress file-naming convention:
 *   Sglms_Foo_Bar  ->  includes/class-sglms-foo-bar.php
 * Sub-namespacing by directory is resolved by scanning the includes
 * subfolders, so a class can live in includes/access/ without the caller
 * needing to know the folder. Lookups are cached per request.
 */
spl_autoload_register(
	function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Sglms_' ) ) {
			return;
		}

		static $map = null;

		$file_name = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		// Top-level includes/ first (cheap, common case).
		$direct = SGLMS_INCLUDES . $file_name;
		if ( is_readable( $direct ) ) {
			require_once $direct;
			return;
		}

		// Build a one-time map of files in the includes subfolders.
		if ( null === $map ) {
			$map        = array();
			$subfolders = array(
				'post-types',
				'access',
				'enrollment',
				'progress',
				'quiz',
				'certificates',
				'downloads',
				'rest',
				'emails',
				'admin',
			);
			foreach ( $subfolders as $folder ) {
				$dir = SGLMS_INCLUDES . $folder . '/';
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				foreach ( (array) glob( $dir . 'class-sglms-*.php' ) as $path ) {
					$map[ basename( $path ) ] = $path;
				}
			}
		}

		if ( isset( $map[ $file_name ] ) ) {
			require_once $map[ $file_name ];
		}
	}
);

/*
 * -------------------------------------------------------------------------
 * Activation / Deactivation / Uninstall
 * -------------------------------------------------------------------------
 *
 * uninstall.php handles full removal; we only register the lifecycle hooks
 * that must run with the plugin loaded.
 */
register_activation_hook(
	__FILE__,
	function () {
		require_once SGLMS_INCLUDES . 'class-sglms-activator.php';
		Sglms_Activator::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		require_once SGLMS_INCLUDES . 'class-sglms-deactivator.php';
		Sglms_Deactivator::deactivate();
	}
);

/*
 * -------------------------------------------------------------------------
 * Self-hosted updates (GitHub releases)
 * -------------------------------------------------------------------------
 *
 * Lets every site running this plugin see updates in its normal Updates
 * screen, pulled from the GitHub repo's releases. The library is vendored,
 * so no Composer is needed at runtime. For a private repo, supply a token
 * via the `sglms_github_auth_token` filter (no code edits required).
 */
$sglms_puc = SGLMS_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
if ( is_readable( $sglms_puc ) ) {
	require_once $sglms_puc;
	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		$sglms_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/revdarkness/stemageddon-lms/',
			SGLMS_FILE,
			'stemageddon-lms'
		);
		$sglms_token = apply_filters( 'sglms_github_auth_token', '' );
		if ( $sglms_token && method_exists( $sglms_updater, 'setAuthentication' ) ) {
			$sglms_updater->setAuthentication( $sglms_token );
		}
		$sglms_api = method_exists( $sglms_updater, 'getVcsApi' ) ? $sglms_updater->getVcsApi() : null;
		if ( $sglms_api && method_exists( $sglms_api, 'enableReleaseAssets' ) ) {
			// Prefer the packaged .zip attached to each GitHub release.
			$sglms_api->enableReleaseAssets();
		}
	}
}

/*
 * -------------------------------------------------------------------------
 * Boot
 * -------------------------------------------------------------------------
 */
add_action(
	'plugins_loaded',
	function () {
		Sglms_Plugin::instance()->run();
	}
);
