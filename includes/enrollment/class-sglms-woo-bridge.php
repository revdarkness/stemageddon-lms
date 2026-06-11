<?php
/**
 * WooCommerce bridge.
 *
 * When WooCommerce is active, a course can map to a product
 * (_sglms_woo_product_id). Completing/processing an order enrolls the buyer
 * in every mapped course, and the course landing's enroll CTA points at the
 * product's add-to-cart URL. Implements the payment adapter interface so the
 * front end stays decoupled from WooCommerce specifics.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Woo_Bridge
 */
class Sglms_Woo_Bridge implements Sglms_Payment_Adapter {

	/**
	 * Hooks (only meaningful when WooCommerce is active).
	 */
	public function __construct() {
		if ( ! self::is_active() ) {
			return;
		}
		add_action( 'woocommerce_order_status_completed', array( $this, 'enroll_from_order' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'enroll_from_order' ) );
		add_filter( 'sglms_course_checkout_url', array( $this, 'checkout_url' ), 10, 2 );
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'WooCommerce' );
	}

	/* ----- Sglms_Payment_Adapter ----- */

	/**
	 * Adapter id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'woocommerce';
	}

	/**
	 * Adapter label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'WooCommerce', 'sglms' );
	}

	/**
	 * Availability.
	 *
	 * @return bool
	 */
	public function is_available() {
		return self::is_active();
	}

	/**
	 * Checkout URL for a course (its product add-to-cart link).
	 *
	 * @param int $course_id Course ID.
	 * @return string
	 */
	public function get_checkout_url( $course_id ) {
		$product_id = (int) get_post_meta( $course_id, '_sglms_woo_product_id', true );
		if ( ! $product_id ) {
			return '';
		}
		return add_query_arg( 'add-to-cart', $product_id, function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) );
	}

	/* ----- Hooks ----- */

	/**
	 * Filter callback feeding get_checkout_url() to the landing CTA.
	 *
	 * @param string $url       Incoming URL.
	 * @param int    $course_id Course ID.
	 * @return string
	 */
	public function checkout_url( $url, $course_id ) {
		$mine = $this->get_checkout_url( $course_id );
		return $mine ? $mine : $url;
	}

	/**
	 * Enroll the buyer in any courses mapped to purchased products.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function enroll_from_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			if ( ! $product_id ) {
				continue;
			}
			foreach ( self::courses_for_product( $product_id ) as $course_id ) {
				Sglms_Enrollment::enroll( $user_id, $course_id, 'woo' );
			}
		}
	}

	/**
	 * Courses mapped to a given product.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]
	 */
	public static function courses_for_product( $product_id ) {
		$ids = get_posts(
			array(
				'post_type'   => 'sglms_course',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_sglms_woo_product_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => (int) $product_id,        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map( 'intval', (array) $ids );
	}
}
