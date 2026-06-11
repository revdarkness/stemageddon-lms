<?php
/**
 * Template loader with theme override support.
 *
 * Lookup order (LearnDash/WooCommerce style): a child/parent theme can
 * override any plugin template by placing it at `yourtheme/sglms/{name}`.
 * Otherwise the plugin's own `templates/{name}` is used.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Templates
 */
class Sglms_Templates {

	/**
	 * Resolve a template file path, honoring theme overrides.
	 *
	 * @param string $name Template filename, e.g. 'access-denied.php'.
	 * @return string Absolute path, or '' if not found.
	 */
	public static function locate( $name ) {
		$name  = ltrim( $name, '/' );
		$theme = locate_template( array( 'sglms/' . $name ) );
		if ( $theme ) {
			return $theme;
		}
		$plugin = SGLMS_TEMPLATES . $name;
		return file_exists( $plugin ) ? $plugin : '';
	}

	/**
	 * Render a template to a string.
	 *
	 * Variables are exposed to the template as $args (an array). Templates
	 * are responsible for escaping their own output.
	 *
	 * @param string $name Template filename.
	 * @param array  $args Variables for the template.
	 * @return string Rendered markup, or '' if the template is missing.
	 */
	public static function render( $name, array $args = array() ) {
		$file = self::locate( $name );
		if ( ! $file ) {
			return '';
		}
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Permalink for one of the plugin's auto-created pages.
	 *
	 * @param string $option_key Option holding the page ID (e.g. 'sglms_page_denied').
	 * @return string URL, falling back to the site home.
	 */
	public static function page_url( $option_key ) {
		$id  = (int) get_option( $option_key );
		$url = $id ? get_permalink( $id ) : '';
		return $url ? $url : home_url( '/' );
	}
}
