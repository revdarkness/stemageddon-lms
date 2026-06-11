<?php
/**
 * Protected downloads.
 *
 * Course/lesson attachments are never linked by their raw media URL. Instead
 * a short-lived, HMAC-signed token is minted for the current user and the
 * file is streamed through /sglms-download/{token} only after verifying the
 * token, the user, and enrollment in the file's course. Downloads are logged
 * (with a hashed IP), the file type is checked against a whitelist, and a
 * "Download all" builds a course zip on the fly.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Downloads
 */
class Sglms_Downloads {

	const QUERY_VAR = 'sglms_dl';
	const TTL       = 900; // 15 minutes.

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 0 );
	}

	/**
	 * Register the download rewrite rule.
	 *
	 * @return void
	 */
	public function add_rewrite() {
		self::register_rule();
	}

	/**
	 * Static rule registration (also called by the activator).
	 *
	 * @return void
	 */
	public static function register_rule() {
		add_rewrite_rule( '^sglms-download/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/* --------------------------------------------------------------------- *
	 * Tokens
	 * --------------------------------------------------------------------- */

	/**
	 * Mint a signed download token.
	 *
	 * @param string $type      'file' or 'zip'.
	 * @param int    $id        Attachment ID (file) or course ID (zip).
	 * @param int    $course_id Course context for authorization.
	 * @param int    $user_id   User (defaults to current).
	 * @return string
	 */
	public static function mint( $type, $id, $course_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$exp     = time() + self::TTL;
		$payload = $type . '|' . (int) $id . '|' . (int) $course_id . '|' . $user_id . '|' . $exp;
		// URL-safe base64 of a non-secret payload (signed separately); not obfuscation.
		$b64 = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $b64 . '.' . self::sign( $payload );
	}

	/**
	 * Validate a token and return its parts, or false.
	 *
	 * @param string $token Token.
	 * @return array{type:string,id:int,course:int,user:int,exp:int}|false
	 */
	public static function validate( $token ) {
		$token = (string) $token;
		$dot   = strrpos( $token, '.' );
		if ( false === $dot ) {
			return false;
		}
		$b64 = substr( $token, 0, $dot );
		$sig = substr( $token, $dot + 1 );

		$payload = base64_decode( strtr( $b64, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $payload ) {
			return false;
		}
		if ( ! hash_equals( self::sign( $payload ), (string) $sig ) ) {
			return false;
		}

		$parts = explode( '|', $payload );
		if ( 5 !== count( $parts ) ) {
			return false;
		}
		list( $type, $id, $course, $user, $exp ) = $parts;
		if ( (int) $exp < time() ) {
			return false;
		}
		return array(
			'type'   => $type,
			'id'     => (int) $id,
			'course' => (int) $course,
			'user'   => (int) $user,
			'exp'    => (int) $exp,
		);
	}

	/**
	 * HMAC signature (first 32 hex chars) of a payload.
	 *
	 * @param string $payload Payload.
	 * @return string
	 */
	private static function sign( $payload ) {
		$secret = (string) get_option( 'sglms_token_secret' );
		return substr( hash_hmac( 'sha256', $payload, $secret ), 0, 32 );
	}

	/* --------------------------------------------------------------------- *
	 * URLs + resource lists
	 * --------------------------------------------------------------------- */

	/**
	 * Tokenized download URL for a single attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $course_id     Course context.
	 * @return string
	 */
	public static function file_url( $attachment_id, $course_id ) {
		return home_url( '/sglms-download/' . self::mint( 'file', $attachment_id, $course_id ) );
	}

	/**
	 * Tokenized "download all" zip URL for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public static function zip_url( $course_id ) {
		return home_url( '/sglms-download/' . self::mint( 'zip', $course_id, $course_id ) );
	}

	/**
	 * All resource attachment IDs for a course (course-level + every lesson),
	 * deduped and whitelist-filtered.
	 *
	 * @param int $course_id Course ID.
	 * @return int[]
	 */
	public static function course_resources( $course_id ) {
		$ids = self::attachment_ids( $course_id );
		foreach ( Sglms_Modules_Schema::ordered_lesson_ids( $course_id ) as $lesson_id ) {
			$ids = array_merge( $ids, self::attachment_ids( $lesson_id ) );
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		return array_values( array_filter( $ids, array( __CLASS__, 'is_allowed' ) ) );
	}

	/**
	 * Attachment IDs stored on a post's _sglms_attachments meta.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function attachment_ids( $post_id ) {
		$raw  = get_post_meta( $post_id, '_sglms_attachments', true );
		$list = json_decode( (string) $raw, true );
		if ( ! is_array( $list ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $list ) ) );
	}

	/* --------------------------------------------------------------------- *
	 * Whitelist
	 * --------------------------------------------------------------------- */

	/**
	 * Allowed download extensions.
	 *
	 * @return string[]
	 */
	public static function whitelist() {
		$default = array( 'pdf', 'zip', 'py', 'ino', 'js', 'html', 'css', 'csv', 'json', 'txt', 'md', 'stl', 'step', 'f3d', 'ipt', 'png', 'jpg', 'jpeg', 'mp4', 'pptx', 'docx', 'xlsx' );
		$stored  = get_option( 'sglms_download_whitelist' );
		$list    = is_array( $stored ) && $stored ? $stored : $default;
		/**
		 * Filter the downloadable file-type whitelist.
		 *
		 * @param string[] $list Allowed extensions (lowercase, no dot).
		 */
		return array_map( 'strtolower', (array) apply_filters( 'sglms_download_whitelist', $list ) );
	}

	/**
	 * Whether an attachment's extension is permitted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_allowed( $attachment_id ) {
		$file = get_attached_file( (int) $attachment_id );
		if ( ! $file ) {
			return false;
		}
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::whitelist(), true );
	}

	/* --------------------------------------------------------------------- *
	 * Controller
	 * --------------------------------------------------------------------- */

	/**
	 * Handle a download request.
	 *
	 * @return void
	 */
	public function maybe_handle() {
		$token = get_query_var( self::QUERY_VAR );
		if ( '' === $token || null === $token ) {
			return;
		}

		$data = self::validate( $token );
		if ( ! $data ) {
			$this->deny( 403, __( 'This download link is invalid or has expired.', 'sglms' ) );
		}

		// The token is bound to a specific user.
		if ( get_current_user_id() !== $data['user'] ) {
			$this->deny( 403, __( 'This download link is not valid for your account.', 'sglms' ) );
		}

		// Must be able to access the course the resource belongs to.
		if ( ! Sglms_Gatekeeper::can_access_course( $data['course'], $data['user'] ) ) {
			$this->deny( 403, __( 'You do not have access to these files.', 'sglms' ) );
		}

		if ( 'zip' === $data['type'] ) {
			$this->stream_zip( $data['course'], $data['user'] );
		} else {
			$this->stream_file( $data['id'], $data['course'], $data['user'] );
		}
		exit;
	}

	/**
	 * Stream a single attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $course_id     Course ID.
	 * @param int $user_id       User ID.
	 * @return void
	 */
	private function stream_file( $attachment_id, $course_id, $user_id ) {
		if ( ! self::is_allowed( $attachment_id ) ) {
			$this->deny( 403, __( 'This file type is not available for download.', 'sglms' ) );
		}
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			$this->deny( 404, __( 'File not found.', 'sglms' ) );
		}

		self::log( $user_id, $attachment_id, $course_id );

		nocache_headers();
		header( 'Content-Type: ' . ( get_post_mime_type( $attachment_id ) ? get_post_mime_type( $attachment_id ) : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}

	/**
	 * Build and stream a zip of all course resources.
	 *
	 * @param int $course_id Course ID.
	 * @param int $user_id   User ID.
	 * @return void
	 */
	private function stream_zip( $course_id, $user_id ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->deny( 501, __( 'Zip downloads are not available on this server.', 'sglms' ) );
		}
		$ids = self::course_resources( $course_id );
		if ( empty( $ids ) ) {
			$this->deny( 404, __( 'There are no downloadable resources for this course.', 'sglms' ) );
		}

		$tmp = wp_tempnam( 'sglms-resources' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->deny( 500, __( 'Could not create the zip archive.', 'sglms' ) );
		}

		$used = array();
		foreach ( $ids as $att_id ) {
			$path = get_attached_file( $att_id );
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}
			$name = basename( $path );
			// Avoid name collisions inside the archive.
			if ( isset( $used[ $name ] ) ) {
				$name = $att_id . '-' . $name;
			}
			$used[ $name ] = true;
			$zip->addFile( $path, $name );
		}
		$zip->close();

		self::log( $user_id, 0, $course_id );

		$slug = sanitize_title( get_the_title( $course_id ) );
		$slug = $slug ? $slug : 'course';

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '-resources.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $tmp );
	}

	/**
	 * Record a download.
	 *
	 * @param int $user_id       User ID.
	 * @param int $attachment_id Attachment ID (0 for a zip).
	 * @param int $course_id     Course ID.
	 * @return void
	 */
	public static function log( $user_id, $attachment_id, $course_id ) {
		global $wpdb;
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$hash = $ip ? hash( 'sha256', $ip . get_option( 'sglms_token_secret' ) ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'sglms_downloads_log',
			array(
				'user_id'       => (int) $user_id,
				'attachment_id' => (int) $attachment_id,
				'course_id'     => (int) $course_id,
				'ip_hash'       => $hash,
				'downloaded_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Count downloads logged for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return int
	 */
	public static function count_for_course( $course_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'sglms_downloads_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE course_id = %d", (int) $course_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * End the request with an error.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Message.
	 * @return void
	 */
	private function deny( $code, $message ) {
		$code = (int) $code;
		status_header( $code );
		nocache_headers();
		wp_die( esc_html( $message ), '', array( 'response' => (int) $code ) );
	}
}
