<?php
/**
 * Certificate template builder.
 *
 * A "Certificate Design" meta box on the sglms_cert_tpl editor: pick a
 * background image and position dynamic fields over a live preview by
 * dragging markers (which sync to numeric X/Y inputs). Fields persist as
 * percentage coordinates via Sglms_Cert_Template::sanitize_fields().
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Cert_Builder
 */
class Sglms_Cert_Builder {

	const NONCE_ACTION = 'sglms_save_cert_tpl';
	const NONCE_NAME   = 'sglms_cert_tpl_nonce';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_sglms_cert_tpl', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_sglms_cert_tpl', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the media library on the template editor.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && 'sglms_cert_tpl' === get_post_type() ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box( 'sglms_cert_design', __( 'Certificate Design', 'sglms' ), array( $this, 'render' ), 'sglms_cert_tpl', 'normal', 'high' );
	}

	/**
	 * Field labels.
	 *
	 * @return array<string,string>
	 */
	private function labels() {
		return array(
			'name'       => __( 'Student name', 'sglms' ),
			'course'     => __( 'Course title', 'sglms' ),
			'date'       => __( 'Completion date', 'sglms' ),
			'cert_id'    => __( 'Certificate ID', 'sglms' ),
			'verify_url' => __( 'Verification URL', 'sglms' ),
			'signature'  => __( 'Signature image', 'sglms' ),
		);
	}

	/**
	 * Render the builder.
	 *
	 * @param WP_Post $post Template post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$fields = Sglms_Cert_Template::get_fields( $post->ID );
		$bg_id  = Sglms_Cert_Template::get_bg_id( $post->ID );
		$bg_url = $bg_id ? wp_get_attachment_image_url( $bg_id, 'large' ) : '';
		$labels = $this->labels();
		$fonts  = array( 'Helvetica', 'Times', 'Courier' );
		$aligns = array(
			'L' => __( 'Left', 'sglms' ),
			'C' => __( 'Center', 'sglms' ),
			'R' => __( 'Right', 'sglms' ),
		);

		echo '<div class="sglms-cert-builder">';

		// Background picker.
		echo '<p>';
		echo '<button type="button" class="button" id="sglms-cert-bg-select">' . esc_html__( 'Select background image', 'sglms' ) . '</button> ';
		echo '<button type="button" class="button" id="sglms-cert-bg-clear">' . esc_html__( 'Clear', 'sglms' ) . '</button>';
		echo '<input type="hidden" name="sglms_cert_bg" id="sglms-cert-bg" value="' . esc_attr( $bg_id ) . '" />';
		echo '</p>';

		// Live preview (Letter landscape ratio 11:8.5).
		echo '<div id="sglms-cert-preview" style="position:relative;width:100%;max-width:760px;aspect-ratio:11/8.5;border:1px solid #c3c4c7;background:#fafafa center/cover no-repeat;' . ( $bg_url ? "background-image:url('" . esc_url( $bg_url ) . "');" : '' ) . '">';
		foreach ( $fields as $key => $f ) {
			$label  = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
			$hidden = empty( $f['enabled'] ) ? ' style="display:none;"' : '';
			printf(
				'<span class="sglms-cert-marker" data-field="%1$s" style="position:absolute;left:%2$s%%;top:%3$s%%;transform:translate(-50%%,-50%%);background:rgba(34,113,177,.85);color:#fff;font-size:11px;padding:2px 6px;border-radius:3px;cursor:move;white-space:nowrap;%4$s">%5$s</span>',
				esc_attr( $key ),
				esc_attr( $f['x'] ),
				esc_attr( $f['y'] ),
				empty( $f['enabled'] ) ? 'display:none;' : '',
				esc_html( $label )
			);
		}
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Drag a label to position it. Coordinates are a percentage of the page, so they scale to any certificate size.', 'sglms' ) . '</p>';

		// Field controls table.
		echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Field', 'sglms' ) . '</th><th>' . esc_html__( 'Show', 'sglms' ) . '</th><th>X%</th><th>Y%</th><th>' . esc_html__( 'Size/Width', 'sglms' ) . '</th><th>' . esc_html__( 'Color', 'sglms' ) . '</th><th>' . esc_html__( 'Font', 'sglms' ) . '</th><th>' . esc_html__( 'Align', 'sglms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $fields as $key => $f ) {
			$base = 'sglms_cert_fields[' . $key . ']';
			echo '<tr data-field="' . esc_attr( $key ) . '">';
			echo '<td>' . esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ) . '</td>';
			echo '<td><input type="checkbox" class="sglms-cf-enabled" name="' . esc_attr( $base ) . '[enabled]" value="1" ' . checked( ! empty( $f['enabled'] ), true, false ) . ' /></td>';
			echo '<td><input type="number" step="0.1" class="sglms-cf-x" name="' . esc_attr( $base ) . '[x]" value="' . esc_attr( $f['x'] ) . '" style="width:64px;" /></td>';
			echo '<td><input type="number" step="0.1" class="sglms-cf-y" name="' . esc_attr( $base ) . '[y]" value="' . esc_attr( $f['y'] ) . '" style="width:64px;" /></td>';

			if ( 'signature' === $key ) {
				echo '<td><input type="number" name="' . esc_attr( $base ) . '[width]" value="' . esc_attr( $f['width'] ) . '" style="width:64px;" title="' . esc_attr__( 'Width (mm)', 'sglms' ) . '" /></td>';
				echo '<td colspan="3"><button type="button" class="button sglms-sig-select">' . esc_html__( 'Select signature', 'sglms' ) . '</button>';
				echo '<input type="hidden" class="sglms-sig-id" name="' . esc_attr( $base ) . '[image_id]" value="' . esc_attr( $f['image_id'] ) . '" /></td>';
			} else {
				echo '<td><input type="number" min="6" max="96" name="' . esc_attr( $base ) . '[size]" value="' . esc_attr( $f['size'] ) . '" style="width:64px;" /></td>';
				echo '<td><input type="text" name="' . esc_attr( $base ) . '[color]" value="' . esc_attr( $f['color'] ) . '" style="width:80px;" placeholder="#222222" /></td>';
				echo '<td><select name="' . esc_attr( $base ) . '[font]">';
				foreach ( $fonts as $fnt ) {
					printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $fnt ), selected( $f['font'], $fnt, false ) );
				}
				echo '</select></td>';
				echo '<td><select name="' . esc_attr( $base ) . '[align]">';
				foreach ( $aligns as $av => $al ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $av ), selected( $f['align'], $av, false ), esc_html( $al ) );
				}
				echo '</select></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		$this->print_assets();
	}

	/**
	 * Save handler.
	 *
	 * @param int     $post_id Template ID.
	 * @param WP_Post $post    Template.
	 * @return void
	 */
	public function save( $post_id, $post ) {
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
		update_post_meta( $post_id, Sglms_Cert_Template::META_BG, isset( $_POST['sglms_cert_bg'] ) ? absint( wp_unslash( $_POST['sglms_cert_bg'] ) ) : 0 );

		$raw   = isset( $_POST['sglms_cert_fields'] ) ? wp_unslash( $_POST['sglms_cert_fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_fields().
		$clean = Sglms_Cert_Template::sanitize_fields( is_array( $raw ) ? $raw : array() );
		update_post_meta( $post_id, Sglms_Cert_Template::META_FIELDS, wp_json_encode( $clean ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Inline media-picker + drag JS.
	 *
	 * @return void
	 */
	private function print_assets() {
		?>
		<script>
		( function () {
			var preview = document.getElementById( 'sglms-cert-preview' );

			// Background image picker.
			var bgInput = document.getElementById( 'sglms-cert-bg' );
			var bgBtn = document.getElementById( 'sglms-cert-bg-select' );
			var bgClear = document.getElementById( 'sglms-cert-bg-clear' );
			if ( bgBtn && window.wp && window.wp.media ) {
				var frame;
				bgBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					frame = frame || window.wp.media( { title: 'Select background', multiple: false, library: { type: 'image' } } );
					frame.off( 'select' );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						bgInput.value = att.id;
						var url = ( att.sizes && att.sizes.large ) ? att.sizes.large.url : att.url;
						preview.style.backgroundImage = "url('" + url + "')";
					} );
					frame.open();
				} );
			}
			if ( bgClear ) {
				bgClear.addEventListener( 'click', function () {
					bgInput.value = '';
					preview.style.backgroundImage = '';
				} );
			}

			// Signature picker(s).
			document.querySelectorAll( '.sglms-sig-select' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( ! ( window.wp && window.wp.media ) ) { return; }
					var f = window.wp.media( { title: 'Select signature', multiple: false, library: { type: 'image' } } );
					f.on( 'select', function () {
						var att = f.state().get( 'selection' ).first().toJSON();
						var hidden = btn.parentNode.querySelector( '.sglms-sig-id' );
						if ( hidden ) { hidden.value = att.id; }
					} );
					f.open();
				} );
			} );

			// Enabled toggles -> show/hide markers.
			function input( field, cls ) {
				var row = document.querySelector( 'tr[data-field="' + field + '"]' );
				return row ? row.querySelector( cls ) : null;
			}
			document.querySelectorAll( '.sglms-cf-enabled' ).forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					var field = cb.closest( 'tr' ).getAttribute( 'data-field' );
					var marker = preview.querySelector( '.sglms-cert-marker[data-field="' + field + '"]' );
					if ( marker ) { marker.style.display = cb.checked ? '' : 'none'; }
				} );
			} );

			// X/Y inputs -> move markers.
			preview.querySelectorAll( '.sglms-cert-marker' ).forEach( function ( marker ) {
				var field = marker.getAttribute( 'data-field' );
				var xi = input( field, '.sglms-cf-x' );
				var yi = input( field, '.sglms-cf-y' );
				if ( xi ) { xi.addEventListener( 'input', function () { marker.style.left = xi.value + '%'; } ); }
				if ( yi ) { yi.addEventListener( 'input', function () { marker.style.top = yi.value + '%'; } ); }

				// Drag the marker.
				var dragging = false;
				marker.addEventListener( 'pointerdown', function ( e ) {
					dragging = true;
					marker.setPointerCapture( e.pointerId );
					e.preventDefault();
				} );
				marker.addEventListener( 'pointermove', function ( e ) {
					if ( ! dragging ) { return; }
					var rect = preview.getBoundingClientRect();
					var x = Math.max( 0, Math.min( 100, ( ( e.clientX - rect.left ) / rect.width ) * 100 ) );
					var y = Math.max( 0, Math.min( 100, ( ( e.clientY - rect.top ) / rect.height ) * 100 ) );
					marker.style.left = x + '%';
					marker.style.top = y + '%';
					if ( xi ) { xi.value = x.toFixed( 1 ); }
					if ( yi ) { yi.value = y.toFixed( 1 ); }
				} );
				marker.addEventListener( 'pointerup', function () { dragging = false; } );
			} );
		} )();
		</script>
		<?php
	}
}
