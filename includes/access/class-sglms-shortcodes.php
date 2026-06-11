<?php
/**
 * Front-end shortcodes for access control and the auto-created pages.
 *
 * - [sglms_protect] gates an inline block of content.
 * - [sglms_access_denied] renders the access-denied template (used on the
 *   denied page).
 * - [sglms_catalog] / [sglms_dashboard] are placeholders so the pages the
 *   activator creates render cleanly until their phases land (3 and 4).
 *
 * The Gutenberg blocks listed in the plan (catalog, protect, enroll-button)
 * ship with the @wordpress/scripts build in the assets phase; these
 * shortcodes are the no-build-step equivalents and the blocks will reuse the
 * same render paths.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Shortcodes
 */
class Sglms_Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public function __construct() {
		add_shortcode( 'sglms_protect', array( $this, 'protect' ) );
		add_shortcode( 'sglms_access_denied', array( $this, 'access_denied' ) );
		add_shortcode( 'sglms_catalog', array( $this, 'catalog_placeholder' ) );
		add_shortcode( 'sglms_dashboard', array( $this, 'dashboard_placeholder' ) );
	}

	/**
	 * [sglms_protect course="12" level="" members="1"] ... [/sglms_protect]
	 *
	 * Shows the wrapped content only to authorized users; otherwise renders a
	 * short restricted notice with a login link.
	 *
	 * @param array       $atts    Attributes.
	 * @param string|null $content Enclosed content.
	 * @return string
	 */
	public function protect( $atts, $content = null ) {
		$atts = shortcode_atts(
			array(
				'course'  => 0,
				'level'   => '',
				'members' => '',
			),
			$atts,
			'sglms_protect'
		);

		if ( $atts['course'] ) {
			$protection = 'course:' . absint( $atts['course'] );
		} elseif ( '' !== $atts['level'] ) {
			$protection = 'level:' . sanitize_key( $atts['level'] );
		} else {
			// Default and explicit members="1" both mean members-only.
			$protection = 'members';
		}

		if ( Sglms_Gatekeeper::evaluate( $protection, get_current_user_id() ) ) {
			// Authorized: render the enclosed content (process nested shortcodes).
			return do_shortcode( (string) $content );
		}

		return $this->restricted_notice();
	}

	/**
	 * [sglms_access_denied] — full access-denied template.
	 *
	 * @return string
	 */
	public function access_denied() {
		$args = array( 'inline' => true );

		// Contextual CTA: the gatekeeper redirects here with ?sglms_from={id}.
		// Read-only display, no state change, so no nonce is required.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$from = isset( $_GET['sglms_from'] ) ? absint( wp_unslash( $_GET['sglms_from'] ) ) : 0;
		if ( $from ) {
			$args['post_id']    = $from;
			$args['protection'] = get_post_meta( $from, '_sglms_protection', true );
			$args['course_id']  = (int) get_post_meta( $from, '_sglms_course_id', true );
		}

		$rendered = Sglms_Templates::render( 'access-denied.php', $args );
		return $rendered ? $rendered : $this->restricted_notice();
	}

	/**
	 * Catalog placeholder (Phase 4 delivers the real catalog).
	 *
	 * @return string
	 */
	public function catalog_placeholder() {
		$rendered = Sglms_Templates::render( 'catalog.php' );
		return $rendered ? $rendered : '<div class="sglms-notice"><p>' . esc_html__( 'The course catalog will appear here.', 'sglms' ) . '</p></div>';
	}

	/**
	 * Dashboard placeholder (Phase 3 delivers the student dashboard).
	 *
	 * @return string
	 */
	public function dashboard_placeholder() {
		if ( ! is_user_logged_in() ) {
			return $this->login_prompt( __( 'Please log in to see your courses.', 'sglms' ) );
		}
		$rendered = Sglms_Templates::render( 'dashboard.php' );
		return $rendered ? $rendered : '<div class="sglms-notice"><p>' . esc_html__( 'Your enrolled courses will appear here.', 'sglms' ) . '</p></div>';
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * A compact restricted-content notice with a login link.
	 *
	 * @return string
	 */
	private function restricted_notice() {
		return $this->login_prompt( __( 'This content is restricted.', 'sglms' ) );
	}

	/**
	 * A message plus a contextual login link.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function login_prompt( $message ) {
		$out  = '<div class="sglms-restricted" style="border:1px solid #dcdcde;border-radius:6px;padding:16px;background:#f6f7f7;">';
		$out .= '<p>' . esc_html( $message ) . '</p>';
		if ( ! is_user_logged_in() ) {
			$out .= '<p><a class="button" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'sglms' ) . '</a></p>';
		}
		$out .= '</div>';
		return $out;
	}
}
