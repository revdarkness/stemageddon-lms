<?php
/**
 * Post meta registration.
 *
 * Registers course, lesson, and universal page-protection meta with explicit
 * sanitize and auth callbacks. Protected (underscore-prefixed) meta exposed
 * to REST always carries an auth_callback so it cannot be read or written
 * without the right capability.
 *
 * Structured/JSON meta (_sglms_modules, _sglms_drip_rules, _sglms_attachments)
 * is kept OUT of REST here and managed through dedicated controllers, so the
 * raw structures are never blindly writable via the meta endpoint.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Meta
 */
class Sglms_Meta {

	/**
	 * Hook onto init.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ), 8 );
	}

	/**
	 * Register all meta.
	 *
	 * @return void
	 */
	public function register() {
		$this->register_course_meta();
		$this->register_lesson_meta();
		$this->register_quiz_meta();
		$this->register_protection_meta();
	}

	/**
	 * Course meta.
	 *
	 * @return void
	 */
	private function register_course_meta() {
		$edit_auth = array( $this, 'can_edit' );

		// Curriculum + drip structures: stored as JSON strings, not REST-exposed.
		register_post_meta(
			'sglms_course',
			Sglms_Modules_Schema::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_modules_json' ),
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_course',
			'_sglms_drip_rules',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_json_string' ),
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_course',
			'_sglms_attachments',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_id_list_json' ),
				'auth_callback'     => $edit_auth,
			)
		);

		// Scalar settings.
		register_post_meta(
			'sglms_course',
			'_sglms_price_mode',
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'free',
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_price_mode' ),
				'auth_callback'     => $edit_auth,
			)
		);

		foreach ( array( '_sglms_woo_product_id', '_sglms_certificate_tpl_id' ) as $key ) {
			register_post_meta(
				'sglms_course',
				$key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'default'           => 0,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => $edit_auth,
				)
			);
		}

		register_post_meta(
			'sglms_course',
			'_sglms_est_duration',
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $edit_auth,
			)
		);
	}

	/**
	 * Lesson meta.
	 *
	 * @return void
	 */
	private function register_lesson_meta() {
		$edit_auth = array( $this, 'can_edit' );

		register_post_meta(
			'sglms_lesson',
			'_sglms_video',
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_lesson',
			'_sglms_attachments',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_id_list_json' ),
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_lesson',
			'_sglms_required',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_lesson',
			'_sglms_duration_min',
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_lesson',
			'_sglms_module_id',
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => $edit_auth,
			)
		);

		// Reverse association maintained by the course builder: which course
		// a lesson belongs to. Lets progress/drip resolve lesson -> course
		// without scanning every course's modules.
		register_post_meta(
			'sglms_lesson',
			'_sglms_course_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $edit_auth,
			)
		);
	}

	/**
	 * Quiz meta.
	 *
	 * @return void
	 */
	private function register_quiz_meta() {
		$edit_auth = array( $this, 'can_edit' );

		// Question bank: holds correct answers, so NEVER exposed to REST.
		register_post_meta(
			'sglms_quiz',
			'_sglms_questions',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_questions_json' ),
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_quiz',
			'_sglms_pass_score',
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 70,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_percent' ),
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_quiz',
			'_sglms_attempt_limit',
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_quiz',
			'_sglms_randomize',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_quiz',
			'_sglms_required',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'auth_callback'     => $edit_auth,
			)
		);

		register_post_meta(
			'sglms_quiz',
			'_sglms_course_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $edit_auth,
			)
		);
	}

	/**
	 * Sanitize the questions JSON via the quiz schema, returning a JSON string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_questions_json( $value ) {
		return wp_json_encode( Sglms_Quiz::sanitize_questions( $value ) );
	}

	/**
	 * Clamp an integer to a 0-100 percentage.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_percent( $value ) {
		return max( 0, min( 100, absint( $value ) ) );
	}

	/**
	 * Universal page/post protection meta.
	 *
	 * Value forms: 'none' | 'members' | 'course:{id}' | 'level:{slug}'.
	 *
	 * @return void
	 */
	private function register_protection_meta() {
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			register_post_meta(
				$post_type,
				'_sglms_protection',
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => 'none',
					'show_in_rest'      => true,
					'sanitize_callback' => array( $this, 'sanitize_protection' ),
					'auth_callback'     => array( $this, 'can_edit' ),
				)
			);
		}
	}

	/* --------------------------------------------------------------------- *
	 * Callbacks
	 * --------------------------------------------------------------------- */

	/**
	 * Capability gate for editing LMS meta.
	 *
	 * @param bool   $allowed   Whether the user can edit (from WP).
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post ID.
	 * @return bool
	 */
	public function can_edit( $allowed, $meta_key = '', $object_id = 0 ) {
		return current_user_can( 'edit_post', (int) $object_id );
	}

	/**
	 * Sanitize the modules JSON via the schema, returning a JSON string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_modules_json( $value ) {
		return wp_json_encode( Sglms_Modules_Schema::sanitize( $value ) );
	}

	/**
	 * Sanitize an arbitrary JSON string: ensure it decodes, re-encode clean.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_json_string( $value ) {
		$decoded = json_decode( (string) $value, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return wp_json_encode( array() );
		}
		return wp_json_encode( $this->deep_clean( $decoded ) );
	}

	/**
	 * Sanitize a list of attachment IDs to a JSON array of positive ints.
	 *
	 * @param string $value Raw value (JSON array or comma list).
	 * @return string
	 */
	public function sanitize_id_list_json( $value ) {
		$list = json_decode( (string) $value, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $list ) ) {
			$list = is_string( $value ) ? explode( ',', $value ) : array();
		}
		$ids = array();
		foreach ( (array) $list as $id ) {
			$id = absint( $id );
			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return wp_json_encode( $ids );
	}

	/**
	 * Recursively sanitize a decoded array's scalars.
	 *
	 * @param array $data Decoded data.
	 * @return array
	 */
	private function deep_clean( array $data ) {
		$out = array();
		foreach ( $data as $key => $val ) {
			$key = is_string( $key ) ? sanitize_text_field( $key ) : $key;
			if ( is_array( $val ) ) {
				$out[ $key ] = $this->deep_clean( $val );
			} elseif ( is_bool( $val ) || is_int( $val ) || is_float( $val ) ) {
				$out[ $key ] = $val;
			} else {
				$out[ $key ] = sanitize_text_field( (string) $val );
			}
		}
		return $out;
	}

	/**
	 * Restrict price mode to the known set.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_price_mode( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'free', 'manual', 'woo' ), true ) ? $value : 'free';
	}

	/**
	 * Coerce to a strict boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function sanitize_bool( $value ) {
		return (bool) rest_sanitize_boolean( $value );
	}

	/**
	 * Validate the protection meta value against its allowed forms.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_protection( $value ) {
		$value = (string) $value;

		if ( in_array( $value, array( 'none', 'members' ), true ) ) {
			return $value;
		}
		if ( preg_match( '/^course:(\d+)$/', $value, $m ) ) {
			return 'course:' . absint( $m[1] );
		}
		if ( preg_match( '/^level:([a-z0-9_\-]+)$/i', $value, $m ) ) {
			return 'level:' . sanitize_key( $m[1] );
		}
		return 'none';
	}
}
