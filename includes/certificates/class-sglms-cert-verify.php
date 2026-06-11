<?php
/**
 * Public certificate verification.
 *
 * Adds the /verify-certificate/{uid} pretty URL (rewrite) that renders a
 * standalone, theme-independent verification page, plus a public REST route
 * returning the same data as JSON. Third parties (and the URL printed on the
 * PDF) use these to confirm a certificate is genuine.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Cert_Verify
 */
class Sglms_Cert_Verify {

	const QUERY_VAR = 'sglms_verify';
	const NS        = 'sglms/v1';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Register the rewrite rule. The one-time flush after a version change is
	 * coordinated centrally in Sglms_Plugin so every module's rules are
	 * registered before the flush runs.
	 *
	 * @return void
	 */
	public function add_rewrite() {
		self::register_rule();
	}

	/**
	 * Add the verification rewrite rule. Static so the activator can call it
	 * before its own flush.
	 *
	 * @return void
	 */
	public static function register_rule() {
		add_rewrite_rule( '^verify-certificate/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([^&]+)' );
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Render the verification page when the query var is present.
	 *
	 * @return void
	 */
	public function maybe_render() {
		$uid = get_query_var( self::QUERY_VAR );
		if ( '' === $uid || null === $uid ) {
			return;
		}

		$data = self::lookup( (string) $uid );
		status_header( $data['valid'] ? 200 : 404 );
		nocache_headers();
		$this->render_page( $data );
		exit;
	}

	/**
	 * Resolve a UID to a verification result.
	 *
	 * @param string $uid Verification ID.
	 * @return array{valid:bool,revoked:bool,name:string,course:string,issued_at:string,uid:string}
	 */
	public static function lookup( $uid ) {
		$record = Sglms_Certificate::get_by_uid( $uid );
		if ( ! $record ) {
			return array(
				'valid'     => false,
				'revoked'   => false,
				'name'      => '',
				'course'    => '',
				'issued_at' => '',
				'uid'       => $uid,
			);
		}
		$user = get_userdata( (int) $record->user_id );
		return array(
			'valid'     => ! (int) $record->revoked,
			'revoked'   => (bool) (int) $record->revoked,
			'name'      => $user ? $user->display_name : '',
			'course'    => get_the_title( (int) $record->course_id ),
			'issued_at' => mysql2date( get_option( 'date_format' ), $record->issued_at ),
			'uid'       => (string) $record->cert_uid,
		);
	}

	/**
	 * Register the public REST verify route.
	 *
	 * @return void
	 */
	public function register_rest() {
		register_rest_route(
			self::NS,
			'/certificates/(?P<uid>[A-Za-z0-9]+)/verify',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_verify' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST verify handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_verify( $request ) {
		$data = self::lookup( (string) $request['uid'] );
		return rest_ensure_response( $data );
	}

	/**
	 * Render a standalone verification page (no theme dependency).
	 *
	 * @param array $data Verification result.
	 * @return void
	 */
	private function render_page( $data ) {
		$title = get_bloginfo( 'name' ) . ' — ' . __( 'Certificate Verification', 'sglms' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<meta name="robots" content="noindex" />
			<title><?php echo esc_html( $title ); ?></title>
			<style>
				body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f6f7f7;margin:0;padding:40px 16px;color:#1d2327;}
				.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:32px;text-align:center;}
				.status{display:inline-block;border-radius:20px;padding:6px 16px;font-weight:600;margin-bottom:16px;}
				.ok{background:#00a32a;color:#fff;}.bad{background:#b32d2e;color:#fff;}
				.row{margin:10px 0;}.label{color:#646970;font-size:13px;}.val{font-size:18px;font-weight:600;}
				.uid{font-family:monospace;color:#646970;font-size:12px;word-break:break-all;margin-top:18px;}
			</style>
		</head>
		<body>
			<div class="box">
				<?php if ( $data['valid'] ) : ?>
					<div class="status ok">✓ <?php esc_html_e( 'Valid certificate', 'sglms' ); ?></div>
					<div class="row"><div class="label"><?php esc_html_e( 'Issued to', 'sglms' ); ?></div><div class="val"><?php echo esc_html( $data['name'] ); ?></div></div>
					<div class="row"><div class="label"><?php esc_html_e( 'Course', 'sglms' ); ?></div><div class="val"><?php echo esc_html( $data['course'] ); ?></div></div>
					<div class="row"><div class="label"><?php esc_html_e( 'Issued', 'sglms' ); ?></div><div class="val"><?php echo esc_html( $data['issued_at'] ); ?></div></div>
					<div class="uid"><?php echo esc_html( $data['uid'] ); ?></div>
				<?php elseif ( $data['revoked'] ) : ?>
					<div class="status bad">✗ <?php esc_html_e( 'Certificate revoked', 'sglms' ); ?></div>
					<p><?php esc_html_e( 'This certificate has been revoked and is no longer valid.', 'sglms' ); ?></p>
				<?php else : ?>
					<div class="status bad">✗ <?php esc_html_e( 'Not found', 'sglms' ); ?></div>
					<p><?php esc_html_e( 'No certificate matches this verification ID.', 'sglms' ); ?></p>
				<?php endif; ?>
			</div>
		</body>
		</html>
		<?php
	}
}
