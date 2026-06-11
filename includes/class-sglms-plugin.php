<?php
/**
 * Core plugin orchestrator.
 *
 * Singleton service container that wires modules together on boot. Later
 * phases register their services here; Phase 0 only loads the text domain
 * and exposes the version/db-upgrade plumbing.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Plugin
 */
final class Sglms_Plugin {

	/**
	 * Single instance.
	 *
	 * @var Sglms_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Whether run() has executed.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Get the shared instance.
	 *
	 * @return Sglms_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin. Idempotent.
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
		// Flush rewrites once per version, after every module has registered
		// its rules (priority 99 runs late on init).
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );

		$this->load_modules();

		/**
		 * Fires after the LMS core has booted and core modules are registered.
		 * Third parties and later phases hook here to add their own services.
		 *
		 * @param Sglms_Plugin $plugin The plugin instance.
		 */
		do_action( 'sglms_init', $this );
	}

	/**
	 * Instantiate and register core modules in the container.
	 *
	 * Each module wires its own WordPress hooks in its constructor (CPTs and
	 * meta on `init`, menus on `admin_menu`), so instantiating here during
	 * `plugins_loaded` registers everything at the right time.
	 *
	 * @return void
	 */
	private function load_modules() {
		// Content model (Phase 1).
		$this->set( 'post_types', new Sglms_Post_Types() );
		$this->set( 'meta', new Sglms_Meta() );

		// Access control (Phase 2).
		$this->set( 'gatekeeper', new Sglms_Gatekeeper() );
		$this->set( 'roles', new Sglms_Roles() );
		$this->set( 'shortcodes', new Sglms_Shortcodes() );

		// Enrollment + progress (Phase 3).
		$this->set( 'enrollment', new Sglms_Enrollment() );
		$this->set( 'progress', new Sglms_Progress() );
		$this->set( 'rest_progress', new Sglms_Rest_Progress() );
		$this->set( 'rest_notes', new Sglms_Rest_Notes() );

		// Front end: catalog, course landing, lesson player (Phase 4).
		$this->set( 'frontend', new Sglms_Frontend() );

		// Quizzes (Phase 5).
		$this->set( 'rest_quiz', new Sglms_Rest_Quiz() );

		// Certificates (Phase 6).
		$this->set( 'certificate', new Sglms_Certificate() );
		$this->set( 'cert_verify', new Sglms_Cert_Verify() );

		// Protected downloads (Phase 7).
		$this->set( 'downloads', new Sglms_Downloads() );

		// Reports, emails, WooCommerce bridge (Phase 8).
		$this->set( 'emails', new Sglms_Emails() );
		$this->set( 'woo_bridge', new Sglms_Woo_Bridge() );

		if ( is_admin() ) {
			$this->set( 'admin_menu', new Sglms_Admin_Menu() );
			$this->set( 'course_builder', new Sglms_Course_Builder() );
			$this->set( 'course_settings', new Sglms_Course_Settings_Metabox() );
			$this->set( 'protection_metabox', new Sglms_Protection_Metabox() );
			$this->set( 'enrollments_screen', new Sglms_Enrollments_Screen() );
			$this->set( 'quiz_builder', new Sglms_Quiz_Builder() );
			$this->set( 'cert_builder', new Sglms_Cert_Builder() );
			$this->set( 'attachments_metabox', new Sglms_Attachments_Metabox() );
			$this->set( 'reports', new Sglms_Reports() );
			$this->set( 'settings', new Sglms_Settings() );
		}
	}

	/**
	 * Register a service in the container.
	 *
	 * @param string $key     Service key.
	 * @param object $service Service instance.
	 * @return void
	 */
	public function set( $key, $service ) {
		$this->services[ $key ] = $service;
	}

	/**
	 * Retrieve a service from the container.
	 *
	 * @param string $key Service key.
	 * @return object|null
	 */
	public function get( $key ) {
		return isset( $this->services[ $key ] ) ? $this->services[ $key ] : null;
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'sglms', false, dirname( SGLMS_BASENAME ) . '/languages' );
	}

	/**
	 * Flush rewrite rules once after a version change so plugin pretty URLs
	 * (certificate verification, downloads) resolve without a manual permalink
	 * save. Runs late on init so all module rules are already registered.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites() {
		if ( get_option( 'sglms_rewrite_version' ) === SGLMS_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'sglms_rewrite_version', SGLMS_VERSION );
	}

	/**
	 * Run the activator's schema routine if the stored DB version is behind.
	 *
	 * Lets schema changes apply on plugin update without requiring
	 * deactivate/reactivate.
	 *
	 * @return void
	 */
	public function maybe_upgrade_db() {
		if ( get_option( 'sglms_db_version' ) === SGLMS_DB_VERSION ) {
			return;
		}
		require_once SGLMS_INCLUDES . 'class-sglms-activator.php';
		Sglms_Activator::install_tables();
		update_option( 'sglms_db_version', SGLMS_DB_VERSION );
	}
}
