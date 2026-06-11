<?php
/**
 * Resources / attachments meta box for courses and lessons.
 *
 * Pick files from the media library; the IDs are stored in the
 * _sglms_attachments meta (whose registered sanitizer keeps a clean JSON
 * array of attachment IDs). The front end serves these only through the
 * tokenized download controller.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Attachments_Metabox
 */
class Sglms_Attachments_Metabox {

	const NONCE_ACTION = 'sglms_save_attachments';
	const NONCE_NAME   = 'sglms_attachments_nonce';

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_sglms_course', array( $this, 'add_meta_box' ) );
		add_action( 'add_meta_boxes_sglms_lesson', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_sglms_course', array( $this, 'save' ) );
		add_action( 'save_post_sglms_lesson', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the media library on course/lesson editors.
	 *
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && in_array( get_post_type(), array( 'sglms_course', 'sglms_lesson' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box( 'sglms_attachments', __( 'Resources / Downloads', 'sglms' ), array( $this, 'render' ), null, 'side' );
	}

	/**
	 * Render the picker + current list.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$ids = Sglms_Downloads::attachment_ids( $post->ID );

		echo '<div class="sglms-attachments">';
		echo '<ul class="sglms-attachments__list" style="margin:0 0 8px;">';
		foreach ( $ids as $id ) {
			$this->render_item( $id );
		}
		echo '</ul>';
		echo '<input type="hidden" name="sglms_attachments" id="sglms-attachments-input" value="' . esc_attr( implode( ',', $ids ) ) . '" />';
		echo '<button type="button" class="button" id="sglms-attachments-add">' . esc_html__( 'Add files', 'sglms' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Files are delivered to enrolled users only, through expiring download links.', 'sglms' ) . '</p>';
		echo '</div>';

		echo '<script type="text/template" id="sglms-attachment-item-tpl">';
		$this->render_item( 0, true );
		echo '</script>';

		$this->print_assets();
	}

	/**
	 * Render one list item.
	 *
	 * @param int  $id          Attachment ID.
	 * @param bool $is_template Whether this is the JS template.
	 * @return void
	 */
	private function render_item( $id, $is_template = false ) {
		$name = $is_template ? '__NAME__' : get_the_title( $id );
		$idv  = $is_template ? '__ID__' : $id;
		echo '<li class="sglms-attachments__item" data-id="' . esc_attr( $idv ) . '" style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:1px solid #f0f0f1;">';
		echo '<span>' . esc_html( $name ) . '</span>';
		echo '<button type="button" class="button-link-delete sglms-attachments__remove" style="color:#b32d2e;">&times;</button>';
		echo '</li>';
	}

	/**
	 * Save handler.
	 *
	 * @param int $post_id Post ID.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$raw = isset( $_POST['sglms_attachments'] ) ? sanitize_text_field( wp_unslash( $_POST['sglms_attachments'] ) ) : '';
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );

		// Stored as JSON via the registered sanitize_id_list_json callback.
		update_post_meta( $post_id, '_sglms_attachments', $ids );
	}

	/**
	 * Inline media-picker JS.
	 *
	 * @return void
	 */
	private function print_assets() {
		?>
		<script>
		( function () {
			var addBtn = document.getElementById( 'sglms-attachments-add' );
			var input = document.getElementById( 'sglms-attachments-input' );
			var list = document.querySelector( '.sglms-attachments__list' );
			var tpl = document.getElementById( 'sglms-attachment-item-tpl' );
			if ( ! addBtn || ! input || ! list ) { return; }

			function ids() {
				return input.value ? input.value.split( ',' ).filter( Boolean ) : [];
			}
			function setIds( arr ) {
				input.value = arr.join( ',' );
			}

			addBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( ! ( window.wp && window.wp.media ) ) { return; }
				var frame = window.wp.media( { title: 'Add resources', multiple: true } );
				frame.on( 'select', function () {
					var sel = frame.state().get( 'selection' ).toJSON();
					var cur = ids();
					sel.forEach( function ( att ) {
						var id = String( att.id );
						if ( cur.indexOf( id ) === -1 ) {
							cur.push( id );
							var html = tpl.innerHTML.replace( /__ID__/g, id ).replace( /__NAME__/g, att.title || att.filename || ( 'File ' + id ) );
							var div = document.createElement( 'div' );
							div.innerHTML = html;
							while ( div.firstChild ) { list.appendChild( div.firstChild ); }
						}
					} );
					setIds( cur );
				} );
				frame.open();
			} );

			list.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'sglms-attachments__remove' ) ) {
					var li = e.target.closest( '.sglms-attachments__item' );
					if ( ! li ) { return; }
					var id = String( li.getAttribute( 'data-id' ) );
					setIds( ids().filter( function ( x ) { return x !== id; } ) );
					li.remove();
				}
			} );
		} )();
		</script>
		<?php
	}
}
