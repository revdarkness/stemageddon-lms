<?php
/**
 * Content Protection meta box.
 *
 * Adds a "Content Access" panel to every public post type so any page, post,
 * or course can be restricted. Writes the single `_sglms_protection` meta;
 * the registered sanitize_callback validates the final value.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Protection_Metabox
 */
class Sglms_Protection_Metabox {

	const NONCE_ACTION = 'sglms_save_protection';
	const NONCE_NAME   = 'sglms_protection_nonce';

	/**
	 * Hook the meta box and save handler.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box on all public post types.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			// A course gates its own lessons; protecting the course landing
			// page itself is still allowed, so include sglms_course too.
			add_meta_box(
				'sglms_protection',
				__( 'Content Access', 'sglms' ),
				array( $this, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$value = get_post_meta( $post->ID, '_sglms_protection', true );
		$value = $value ? $value : 'none';

		$mode   = 'none';
		$course = 0;
		$level  = '';
		if ( 'members' === $value ) {
			$mode = 'members';
		} elseif ( 0 === strpos( $value, 'course:' ) ) {
			$mode   = 'course';
			$course = (int) substr( $value, 7 );
		} elseif ( 0 === strpos( $value, 'level:' ) ) {
			$mode  = 'level';
			$level = substr( $value, 6 );
		}

		$courses = get_posts(
			array(
				'post_type'   => 'sglms_course',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		$radios = array(
			'none'    => __( 'Public — anyone can view', 'sglms' ),
			'members' => __( 'Members only — logged-in users', 'sglms' ),
			'course'  => __( 'Enrolled in a specific course', 'sglms' ),
			'level'   => __( 'Membership level', 'sglms' ),
		);

		echo '<div class="sglms-protection">';
		foreach ( $radios as $key => $label ) {
			printf(
				'<p><label><input type="radio" name="sglms_protection_mode" value="%1$s" %2$s /> %3$s</label></p>',
				esc_attr( $key ),
				checked( $mode, $key, false ),
				esc_html( $label )
			);

			if ( 'course' === $key ) {
				echo '<p style="margin-left:24px;"><select name="sglms_protection_course" style="max-width:100%;">';
				echo '<option value="0">' . esc_html__( '— Select a course —', 'sglms' ) . '</option>';
				foreach ( $courses as $c ) {
					printf(
						'<option value="%1$d" %2$s>%3$s</option>',
						(int) $c->ID,
						selected( $course, (int) $c->ID, false ),
						esc_html( $c->post_title )
					);
				}
				echo '</select></p>';
			}

			if ( 'level' === $key ) {
				printf(
					'<p style="margin-left:24px;"><input type="text" name="sglms_protection_level" value="%1$s" placeholder="%2$s" style="max-width:100%%;" /></p>',
					esc_attr( $level ),
					esc_attr__( 'level-slug', 'sglms' )
				);
			}
		}
		echo '<p class="description">' . esc_html__( 'Restricted content never appears in search, feeds, REST, or excerpts.', 'sglms' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Save handler.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$mode = isset( $_POST['sglms_protection_mode'] ) ? sanitize_key( wp_unslash( $_POST['sglms_protection_mode'] ) ) : 'none';

		switch ( $mode ) {
			case 'members':
				$value = 'members';
				break;
			case 'course':
				$cid   = isset( $_POST['sglms_protection_course'] ) ? absint( wp_unslash( $_POST['sglms_protection_course'] ) ) : 0;
				$value = $cid ? 'course:' . $cid : 'none';
				break;
			case 'level':
				$lvl   = isset( $_POST['sglms_protection_level'] ) ? sanitize_key( wp_unslash( $_POST['sglms_protection_level'] ) ) : '';
				$value = $lvl ? 'level:' . $lvl : 'none';
				break;
			default:
				$value = 'none';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// update_post_meta runs the registered sanitize_callback as a final guard.
		update_post_meta( $post_id, '_sglms_protection', $value );
	}
}
