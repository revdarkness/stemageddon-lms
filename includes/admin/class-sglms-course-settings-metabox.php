<?php
/**
 * Course Settings meta box.
 *
 * Pricing/access mode, optional WooCommerce product mapping, and the
 * certificate template for a course. Closes the Phase 6 gap where a course
 * could not choose its certificate template from the editor.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Course_Settings_Metabox
 */
class Sglms_Course_Settings_Metabox {

	const NONCE_ACTION = 'sglms_save_course_settings';
	const NONCE_NAME   = 'sglms_course_settings_nonce';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_sglms_course', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_sglms_course', array( $this, 'save' ) );
	}

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box( 'sglms_course_settings', __( 'Course Settings', 'sglms' ), array( $this, 'render' ), 'sglms_course', 'side' );
	}

	/**
	 * Render.
	 *
	 * @param WP_Post $post Course.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$price = get_post_meta( $post->ID, '_sglms_price_mode', true );
		$price = $price ? $price : 'free';
		$woo   = (int) get_post_meta( $post->ID, '_sglms_woo_product_id', true );
		$tpl   = (int) get_post_meta( $post->ID, '_sglms_certificate_tpl_id', true );
		$dur   = get_post_meta( $post->ID, '_sglms_est_duration', true );

		$modes = array(
			'free'   => __( 'Free (self-enroll)', 'sglms' ),
			'manual' => __( 'Manual enrollment only', 'sglms' ),
			'woo'    => __( 'WooCommerce product', 'sglms' ),
		);

		echo '<p><label>' . esc_html__( 'Enrollment mode', 'sglms' ) . '<br /><select name="sglms_price_mode" style="width:100%;">';
		foreach ( $modes as $val => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $val ), selected( $price, $val, false ), esc_html( $label ) );
		}
		echo '</select></label></p>';

		if ( Sglms_Woo_Bridge::is_active() ) {
			echo '<p><label>' . esc_html__( 'WooCommerce product ID', 'sglms' ) . '<br />';
			echo '<input type="number" name="sglms_woo_product_id" value="' . esc_attr( $woo ) . '" style="width:100%;" /></label></p>';
		} else {
			echo '<input type="hidden" name="sglms_woo_product_id" value="' . esc_attr( $woo ) . '" />';
		}

		echo '<p><label>' . esc_html__( 'Estimated duration', 'sglms' ) . '<br />';
		echo '<input type="text" name="sglms_est_duration" value="' . esc_attr( $dur ) . '" placeholder="' . esc_attr__( 'e.g. 4 hours', 'sglms' ) . '" style="width:100%;" /></label></p>';

		$templates = get_posts(
			array(
				'post_type'   => 'sglms_cert_tpl',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		echo '<p><label>' . esc_html__( 'Certificate template', 'sglms' ) . '<br /><select name="sglms_certificate_tpl_id" style="width:100%;">';
		echo '<option value="0">' . esc_html__( 'Default layout', 'sglms' ) . '</option>';
		foreach ( $templates as $t ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $t->ID, selected( $tpl, (int) $t->ID, false ), esc_html( $t->post_title ) );
		}
		echo '</select></label></p>';
	}

	/**
	 * Save.
	 *
	 * @param int $post_id Course ID.
	 * @return void
	 */
	public function save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$mode = isset( $_POST['sglms_price_mode'] ) ? sanitize_key( wp_unslash( $_POST['sglms_price_mode'] ) ) : 'free';
		update_post_meta( $post_id, '_sglms_price_mode', in_array( $mode, array( 'free', 'manual', 'woo' ), true ) ? $mode : 'free' );
		update_post_meta( $post_id, '_sglms_woo_product_id', isset( $_POST['sglms_woo_product_id'] ) ? absint( wp_unslash( $_POST['sglms_woo_product_id'] ) ) : 0 );
		update_post_meta( $post_id, '_sglms_certificate_tpl_id', isset( $_POST['sglms_certificate_tpl_id'] ) ? absint( wp_unslash( $_POST['sglms_certificate_tpl_id'] ) ) : 0 );
		update_post_meta( $post_id, '_sglms_est_duration', isset( $_POST['sglms_est_duration'] ) ? sanitize_text_field( wp_unslash( $_POST['sglms_est_duration'] ) ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
