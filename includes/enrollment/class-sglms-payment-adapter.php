<?php
/**
 * Payment adapter contract.
 *
 * Enrollment sources beyond free/manual implement this interface. The
 * WooCommerce bridge (Phase 8) is the first concrete adapter: it maps a
 * course to a product and enrolls the buyer on purchase. Stripe-direct is a
 * future adapter. Adapters register via the `sglms_payment_adapters` filter.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interface Sglms_Payment_Adapter
 */
interface Sglms_Payment_Adapter {

	/**
	 * Unique adapter id (e.g. 'woocommerce', 'stripe').
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether this adapter is currently usable (dependency active, configured).
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * URL that begins checkout/purchase for a course.
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public function get_checkout_url( $course_id );
}
