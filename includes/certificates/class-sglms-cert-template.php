<?php
/**
 * Certificate template model.
 *
 * A template (sglms_cert_tpl post) stores a background image and a set of
 * positioned fields. Positions are percentages of the page (0-100) so they
 * are page-size independent and map directly between the visual builder and
 * the PDF renderer.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Cert_Template
 */
class Sglms_Cert_Template {

	const META_BG     = '_sglms_cert_bg';
	const META_FIELDS = '_sglms_cert_fields';

	/**
	 * The dynamic field keys a certificate can render.
	 *
	 * @return string[]
	 */
	public static function field_keys() {
		return array( 'name', 'course', 'date', 'cert_id', 'verify_url', 'signature' );
	}

	/**
	 * Default field definitions (used when a template has none, and as the
	 * baseline merged under stored values).
	 *
	 * @return array<string,array>
	 */
	public static function default_fields() {
		return array(
			'name'       => array(
				'key'     => 'name',
				'enabled' => true,
				'x'       => 50,
				'y'       => 42,
				'size'    => 32,
				'color'   => '#222222',
				'font'    => 'Times',
				'align'   => 'C',
			),
			'course'     => array(
				'key'     => 'course',
				'enabled' => true,
				'x'       => 50,
				'y'       => 56,
				'size'    => 18,
				'color'   => '#444444',
				'font'    => 'Helvetica',
				'align'   => 'C',
			),
			'date'       => array(
				'key'     => 'date',
				'enabled' => true,
				'x'       => 50,
				'y'       => 67,
				'size'    => 12,
				'color'   => '#666666',
				'font'    => 'Helvetica',
				'align'   => 'C',
			),
			'cert_id'    => array(
				'key'     => 'cert_id',
				'enabled' => true,
				'x'       => 50,
				'y'       => 88,
				'size'    => 9,
				'color'   => '#888888',
				'font'    => 'Courier',
				'align'   => 'C',
			),
			'verify_url' => array(
				'key'     => 'verify_url',
				'enabled' => true,
				'x'       => 50,
				'y'       => 92,
				'size'    => 8,
				'color'   => '#888888',
				'font'    => 'Courier',
				'align'   => 'C',
			),
			'signature'  => array(
				'key'      => 'signature',
				'enabled'  => false,
				'x'        => 75,
				'y'        => 78,
				'image_id' => 0,
				'width'    => 40,
			),
		);
	}

	/**
	 * Resolved fields for a template: stored values merged over defaults.
	 *
	 * @param int $template_id Template ID (0 = use defaults).
	 * @return array<string,array>
	 */
	public static function get_fields( $template_id ) {
		$defaults = self::default_fields();
		if ( ! $template_id ) {
			return $defaults;
		}
		$stored = get_post_meta( $template_id, self::META_FIELDS, true );
		$stored = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$out = array();
		foreach ( $defaults as $key => $def ) {
			$out[ $key ] = isset( $stored[ $key ] ) && is_array( $stored[ $key ] )
				? array_merge( $def, $stored[ $key ] )
				: $def;
		}
		return $out;
	}

	/**
	 * Background image attachment ID for a template.
	 *
	 * @param int $template_id Template ID.
	 * @return int
	 */
	public static function get_bg_id( $template_id ) {
		return $template_id ? (int) get_post_meta( $template_id, self::META_BG, true ) : 0;
	}

	/**
	 * Absolute filesystem path of the background image, or '' if none.
	 *
	 * @param int $template_id Template ID.
	 * @return string
	 */
	public static function get_bg_path( $template_id ) {
		$bg = self::get_bg_id( $template_id );
		if ( ! $bg ) {
			return '';
		}
		$path = get_attached_file( $bg );
		return ( $path && file_exists( $path ) ) ? $path : '';
	}

	/**
	 * Sanitize a posted fields structure to the strict schema.
	 *
	 * @param array $raw Raw fields (key => settings).
	 * @return array<string,array>
	 */
	public static function sanitize_fields( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$defaults = self::default_fields();
		$fonts    = array( 'Helvetica', 'Times', 'Courier' );
		$aligns   = array( 'L', 'C', 'R' );
		$out      = array();

		foreach ( $defaults as $key => $def ) {
			$in  = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();
			$row = array(
				'key'     => $key,
				'enabled' => ! empty( $in['enabled'] ),
				'x'       => isset( $in['x'] ) ? max( 0, min( 100, (float) $in['x'] ) ) : $def['x'],
				'y'       => isset( $in['y'] ) ? max( 0, min( 100, (float) $in['y'] ) ) : $def['y'],
			);

			if ( 'signature' === $key ) {
				$row['image_id'] = isset( $in['image_id'] ) ? absint( $in['image_id'] ) : 0;
				$row['width']    = isset( $in['width'] ) ? max( 5, min( 100, (float) $in['width'] ) ) : $def['width'];
			} else {
				$row['size']  = isset( $in['size'] ) ? max( 6, min( 96, absint( $in['size'] ) ) ) : $def['size'];
				$color        = isset( $in['color'] ) ? sanitize_hex_color( $in['color'] ) : '';
				$row['color'] = $color ? $color : $def['color'];
				$row['font']  = ( isset( $in['font'] ) && in_array( $in['font'], $fonts, true ) ) ? $in['font'] : $def['font'];
				$row['align'] = ( isset( $in['align'] ) && in_array( $in['align'], $aligns, true ) ) ? $in['align'] : $def['align'];
			}
			$out[ $key ] = $row;
		}
		return $out;
	}
}
