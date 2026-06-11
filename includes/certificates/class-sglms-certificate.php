<?php
/**
 * Certificate model: server-side PDF generation, records, lifecycle.
 *
 * Auto-issues on course completion, renders a PDF with FPDF (vendored,
 * GPL-compatible), stores a record with a non-sequential 20-char verification
 * ID, and streams downloads only to the owner or a manager. PDFs live in the
 * protected uploads directory and are never served by a guessable URL.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Certificate
 */
class Sglms_Certificate {

	/**
	 * Hooks: auto-issue + download handler.
	 */
	public function __construct() {
		add_action( 'sglms_course_completed', array( __CLASS__, 'issue' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_download' ) );
	}

	/**
	 * Certificates table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sglms_certificates';
	}

	/**
	 * Storage directory for generated PDFs (inside the protected uploads dir).
	 *
	 * @return string
	 */
	public static function dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'sglms-protected/certificates';
	}

	/* --------------------------------------------------------------------- *
	 * Lifecycle
	 * --------------------------------------------------------------------- */

	/**
	 * Issue a certificate for a user/course (idempotent for active certs).
	 *
	 * @param int $user_id     User ID.
	 * @param int $course_id   Course ID.
	 * @param int $template_id Optional template; resolved from course meta otherwise.
	 * @return object|WP_Error The certificate record, or error.
	 */
	public static function issue( $user_id, $course_id, $template_id = 0 ) {
		global $wpdb;
		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;

		$existing = self::get_active( $user_id, $course_id );
		if ( $existing ) {
			return $existing;
		}

		if ( ! $template_id ) {
			$template_id = (int) get_post_meta( $course_id, '_sglms_certificate_tpl_id', true );
		}
		if ( ! $template_id ) {
			$template_id = self::default_template_id();
		}

		// Non-sequential, collision-checked verification id.
		do {
			$uid = wp_generate_password( 20, false, false );
		} while ( self::get_by_uid( $uid ) );

		$path = self::generate_pdf( $user_id, $course_id, $uid, $template_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table(),
			array(
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'template_id' => (int) $template_id,
				'cert_uid'    => $uid,
				'pdf_path'    => $path,
				'revoked'     => 0,
				'issued_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		$record = self::get_by_uid( $uid );

		/**
		 * Fires when a certificate is issued.
		 *
		 * @param object $record  Certificate record.
		 * @param int    $user_id User ID.
		 * @param int    $course_id Course ID.
		 */
		do_action( 'sglms_certificate_issued', $record, $user_id, $course_id );
		return $record;
	}

	/**
	 * Re-render a certificate's PDF in place (same UID/record).
	 *
	 * @param string $uid Verification ID.
	 * @return object|WP_Error
	 */
	public static function regenerate( $uid ) {
		$record = self::get_by_uid( $uid );
		if ( ! $record ) {
			return new WP_Error( 'sglms_no_cert', __( 'Certificate not found.', 'sglms' ) );
		}
		$path = self::generate_pdf( (int) $record->user_id, (int) $record->course_id, $record->cert_uid, (int) $record->template_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table(), array( 'pdf_path' => $path ), array( 'cert_uid' => $uid ), array( '%s' ), array( '%s' ) );
		return self::get_by_uid( $uid );
	}

	/**
	 * Revoke a certificate.
	 *
	 * @param string $uid Verification ID.
	 * @return void
	 */
	public static function revoke( $uid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table(), array( 'revoked' => 1 ), array( 'cert_uid' => $uid ), array( '%d' ), array( '%s' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Reads
	 * --------------------------------------------------------------------- */

	/**
	 * Active (non-revoked) certificate for a user/course, or null.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return object|null
	 */
	public static function get_active( $user_id, $course_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE user_id = %d AND course_id = %d AND revoked = 0 ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $course_id
			)
		);
	}

	/**
	 * Look up a certificate by its verification ID.
	 *
	 * @param string $uid Verification ID.
	 * @return object|null
	 */
	public static function get_by_uid( $uid ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE cert_uid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(string) $uid
			)
		);
	}

	/**
	 * A user's active certificates.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,object>
	 */
	public static function get_user_certificates( $user_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE user_id = %d AND revoked = 0 ORDER BY issued_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id
			)
		);
	}

	/**
	 * Public verification URL for a certificate.
	 *
	 * @param string $uid Verification ID.
	 * @return string
	 */
	public static function verify_url( $uid ) {
		return home_url( '/verify-certificate/' . rawurlencode( $uid ) );
	}

	/**
	 * Owner-checked download URL.
	 *
	 * @param string $uid Verification ID.
	 * @return string
	 */
	public static function download_url( $uid ) {
		return add_query_arg( 'sglms_cert_dl', rawurlencode( $uid ), home_url( '/' ) );
	}

	/* --------------------------------------------------------------------- *
	 * PDF generation
	 * --------------------------------------------------------------------- */

	/**
	 * Render the certificate PDF to disk and return its path.
	 *
	 * @param int    $user_id     User ID.
	 * @param int    $course_id   Course ID.
	 * @param string $uid         Verification ID.
	 * @param int    $template_id Template ID (0 = defaults).
	 * @return string|WP_Error Absolute path, or error.
	 */
	public static function generate_pdf( $user_id, $course_id, $uid, $template_id ) {
		require_once SGLMS_PATH . 'lib/fpdf/fpdf.php';

		$fields = Sglms_Cert_Template::get_fields( $template_id );
		$bg     = Sglms_Cert_Template::get_bg_path( $template_id );
		$values = self::field_values( $user_id, $course_id, $uid );

		try {
			$pdf = new FPDF( 'L', 'mm', 'Letter' );
			$pdf->SetAutoPageBreak( false );
			$pdf->AddPage();
			$pw = $pdf->GetPageWidth();
			$ph = $pdf->GetPageHeight();

			if ( $bg ) {
				$pdf->Image( $bg, 0, 0, $pw, $ph );
			}

			foreach ( $fields as $f ) {
				if ( empty( $f['enabled'] ) ) {
					continue;
				}
				$x = ( (float) $f['x'] / 100 ) * $pw;
				$y = ( (float) $f['y'] / 100 ) * $ph;

				if ( 'signature' === $f['key'] ) {
					$sig = $f['image_id'] ? get_attached_file( (int) $f['image_id'] ) : '';
					if ( $sig && file_exists( $sig ) ) {
						$pdf->Image( $sig, $x, $y, (float) $f['width'] );
					}
					continue;
				}

				$text = isset( $values[ $f['key'] ] ) ? $values[ $f['key'] ] : '';
				if ( '' === $text ) {
					continue;
				}

				list( $r, $g, $b ) = self::hex_to_rgb( $f['color'] );
				$pdf->SetTextColor( $r, $g, $b );
				$pdf->SetFont( $f['font'], '', (int) $f['size'] );

				$tw = $pdf->GetStringWidth( $text );
				$tx = $x;
				if ( 'C' === $f['align'] ) {
					$tx = $x - ( $tw / 2 );
				} elseif ( 'R' === $f['align'] ) {
					$tx = $x - $tw;
				}
				$pdf->Text( $tx, $y, $text );
			}

			$dir = self::dir();
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$path = $dir . '/' . $uid . '.pdf';
			$pdf->Output( 'F', $path );

			return $path;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sglms_pdf_failed', $e->getMessage() );
		}
	}

	/**
	 * Resolve dynamic field values.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param string $uid       Verification ID.
	 * @return array<string,string>
	 */
	private static function field_values( $user_id, $course_id, $uid ) {
		$user = get_userdata( $user_id );
		$done = Sglms_Enrollment::get_status( $user_id, $course_id );
		$when = time(); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		return array(
			'name'       => $user ? $user->display_name : '',
			'course'     => get_the_title( $course_id ),
			'date'       => date_i18n( get_option( 'date_format' ), $when ),
			'cert_id'    => $uid,
			'verify_url' => self::verify_url( $uid ),
		);
	}

	/**
	 * Convert a #rrggbb hex color to an [r,g,b] triplet.
	 *
	 * @param string $hex Hex color.
	 * @return array{0:int,1:int,2:int}
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * First published certificate template, or 0.
	 *
	 * @return int
	 */
	private static function default_template_id() {
		$ids = get_posts(
			array(
				'post_type'   => 'sglms_cert_tpl',
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
				'orderby'     => 'date',
				'order'       => 'ASC',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/* --------------------------------------------------------------------- *
	 * Download
	 * --------------------------------------------------------------------- */

	/**
	 * Stream a certificate PDF to its owner (or a manager).
	 *
	 * @return void
	 */
	public function maybe_download() {
		if ( ! isset( $_GET['sglms_cert_dl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$uid    = sanitize_text_field( wp_unslash( $_GET['sglms_cert_dl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$record = self::get_by_uid( $uid );

		if ( ! $record || (int) $record->revoked ) {
			wp_die( esc_html__( 'Certificate not available.', 'sglms' ), '', array( 'response' => 404 ) );
		}
		$is_owner = ( get_current_user_id() === (int) $record->user_id );
		if ( ! $is_owner && ! current_user_can( 'sglms_issue_certificates' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this certificate.', 'sglms' ), '', array( 'response' => 403 ) );
		}
		if ( ! $record->pdf_path || ! file_exists( $record->pdf_path ) ) {
			// Try regenerating once if the file went missing.
			$re = self::regenerate( $uid );
			if ( is_wp_error( $re ) || ! file_exists( $re->pdf_path ) ) {
				wp_die( esc_html__( 'Certificate file is missing.', 'sglms' ), '', array( 'response' => 404 ) );
			}
			$record = $re;
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="certificate-' . $uid . '.pdf"' );
		header( 'Content-Length: ' . filesize( $record->pdf_path ) );
		readfile( $record->pdf_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
