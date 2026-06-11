<?php
/**
 * Settings screen (LMS → Settings).
 *
 * Uses the Settings API. Covers page assignments, the download whitelist,
 * email toggles, the default certificate template, and the destructive
 * uninstall opt-in.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Settings
 */
class Sglms_Settings {

	const SLUG  = 'sglms-settings';
	const GROUP = 'sglms_settings_group';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Register the submenu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			Sglms_Post_Types::MENU_SLUG,
			__( 'Settings', 'sglms' ),
			__( 'Settings', 'sglms' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			'sglms_page_dashboard',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			self::GROUP,
			'sglms_page_catalog',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			self::GROUP,
			'sglms_page_denied',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			)
		);
		register_setting(
			self::GROUP,
			'sglms_delete_data_on_uninstall',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
			)
		);
		register_setting( self::GROUP, 'sglms_download_whitelist', array( 'sanitize_callback' => array( $this, 'sanitize_whitelist' ) ) );
		register_setting( self::GROUP, 'sglms_settings', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	/**
	 * Sanitize a checkbox to 0/1.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public function sanitize_bool( $value ) {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Sanitize the whitelist textarea (comma/space/newline list) to an array.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public function sanitize_whitelist( $value ) {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = preg_split( '/[\s,]+/', (string) $value );
		}
		$out = array();
		foreach ( (array) $parts as $p ) {
			$p = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $p ) );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize the misc settings array (email toggles, default cert template).
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function sanitize_settings( $value ) {
		$value = is_array( $value ) ? $value : array();
		return array(
			'email_enrollment' => empty( $value['email_enrollment'] ) ? 0 : 1,
			'email_completion' => empty( $value['email_completion'] ) ? 0 : 1,
			'email_instructor' => empty( $value['email_instructor'] ) ? 0 : 1,
			'default_cert_tpl' => isset( $value['default_cert_tpl'] ) ? absint( $value['default_cert_tpl'] ) : 0,
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings  = wp_parse_args(
			get_option( 'sglms_settings', array() ),
			array(
				'email_enrollment' => 1,
				'email_completion' => 1,
				'email_instructor' => 1,
				'default_cert_tpl' => 0,
			)
		);
		$whitelist = Sglms_Downloads::whitelist();
		$pages     = get_pages();
		$templates = get_posts(
			array(
				'post_type'   => 'sglms_cert_tpl',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'STEMageddon LMS Settings', 'sglms' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );

		echo '<h2>' . esc_html__( 'Pages', 'sglms' ) . '</h2><table class="form-table">';
		$this->page_row( __( 'Dashboard', 'sglms' ), 'sglms_page_dashboard', $pages );
		$this->page_row( __( 'Catalog', 'sglms' ), 'sglms_page_catalog', $pages );
		$this->page_row( __( 'Access denied', 'sglms' ), 'sglms_page_denied', $pages );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Emails', 'sglms' ) . '</h2><table class="form-table">';
		$this->toggle_row( __( 'Enrollment confirmation to student', 'sglms' ), 'email_enrollment', $settings['email_enrollment'] );
		$this->toggle_row( __( 'Completion email to student', 'sglms' ), 'email_completion', $settings['email_completion'] );
		$this->toggle_row( __( 'New-enrollment notice to instructor', 'sglms' ), 'email_instructor', $settings['email_instructor'] );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Certificates', 'sglms' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Default template', 'sglms' ) . '</th><td><select name="sglms_settings[default_cert_tpl]">';
		echo '<option value="0">' . esc_html__( 'Built-in default layout', 'sglms' ) . '</option>';
		foreach ( $templates as $t ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $t->ID, selected( $settings['default_cert_tpl'], (int) $t->ID, false ), esc_html( $t->post_title ) );
		}
		echo '</select></td></tr></table>';

		echo '<h2>' . esc_html__( 'Downloads', 'sglms' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Allowed file types', 'sglms' ) . '</th><td>';
		echo '<input type="text" class="large-text" name="sglms_download_whitelist" value="' . esc_attr( implode( ', ', $whitelist ) ) . '" />';
		echo '<p class="description">' . esc_html__( 'Comma-separated extensions (no dots).', 'sglms' ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'Data', 'sglms' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'On uninstall', 'sglms' ) . '</th><td><label><input type="checkbox" name="sglms_delete_data_on_uninstall" value="1" ' . checked( get_option( 'sglms_delete_data_on_uninstall' ), 1, false ) . ' /> ';
		echo esc_html__( 'Delete all LMS data when the plugin is deleted', 'sglms' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. Deactivation never deletes data.', 'sglms' ) . '</p></td></tr></table>';

		submit_button();
		echo '</form></div>';
	}

	/**
	 * A page-select settings row.
	 *
	 * @param string    $label  Label.
	 * @param string    $option Option key.
	 * @param WP_Post[] $pages  Pages.
	 * @return void
	 */
	private function page_row( $label, $option, $pages ) {
		$current = (int) get_option( $option );
		echo '<tr><th>' . esc_html( $label ) . '</th><td><select name="' . esc_attr( $option ) . '">';
		echo '<option value="0">' . esc_html__( '— None —', 'sglms' ) . '</option>';
		foreach ( $pages as $p ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $p->ID, selected( $current, (int) $p->ID, false ), esc_html( $p->post_title ) );
		}
		echo '</select></td></tr>';
	}

	/**
	 * A toggle settings row (stored in the sglms_settings array).
	 *
	 * @param string $label Label.
	 * @param string $key   Settings key.
	 * @param int    $value Current value.
	 * @return void
	 */
	private function toggle_row( $label, $key, $value ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="sglms_settings[' . esc_attr( $key ) . ']" value="1" ' . checked( $value, 1, false ) . ' /> ' . esc_html__( 'Enabled', 'sglms' ) . '</label></td></tr>';
	}
}
