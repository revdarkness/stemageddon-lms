<?php
/**
 * Course Builder meta box.
 *
 * Renders a curriculum panel on the course editor: add modules, name them,
 * and assign lessons to each. Persists through Sglms_Modules_Schema (the
 * single source of truth) and maintains the reverse lesson -> course /
 * lesson -> module association.
 *
 * This is the server-rendered, no-build-step builder. Drag-to-reorder
 * (REQ-C04) layers on later via @wordpress/scripts without changing the
 * stored data shape.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Course_Builder
 */
class Sglms_Course_Builder {

	const NONCE_ACTION = 'sglms_save_curriculum';
	const NONCE_NAME   = 'sglms_curriculum_nonce';

	/**
	 * Hook the meta box and save handler.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_sglms_course', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_sglms_course', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the curriculum meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'sglms_curriculum',
			__( 'Course Curriculum', 'sglms' ),
			array( $this, 'render' ),
			'sglms_course',
			'normal',
			'high'
		);
	}

	/**
	 * Render the builder UI.
	 *
	 * @param WP_Post $post Course post.
	 * @return void
	 */
	public function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$modules = Sglms_Modules_Schema::get( $post->ID );
		$lessons = get_posts(
			array(
				'post_type'        => 'sglms_lesson',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		echo '<div class="sglms-builder">';

		if ( empty( $lessons ) ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p>';
			printf(
				/* translators: %s: URL to add a new lesson. */
				wp_kses_post( __( 'No lessons exist yet. <a href="%s">Create lessons</a> first, then return here to assign them to modules.', 'sglms' ) ),
				esc_url( admin_url( 'post-new.php?post_type=sglms_lesson' ) )
			);
			echo '</p></div>';
		}

		echo '<p class="description">' . esc_html__( 'Group your lessons into modules. Lessons play in the order shown within each module.', 'sglms' ) . '</p>';

		echo '<div id="sglms-modules">';
		if ( empty( $modules ) ) {
			// Render one empty module to start.
			$this->render_module(
				array(
					'id'      => '',
					'title'   => '',
					'lessons' => array(),
				),
				$lessons,
				'0',
				false
			);
		} else {
			foreach ( $modules as $i => $module ) {
				$this->render_module( $module, $lessons, (string) $i, false );
			}
		}
		echo '</div>';

		echo '<p><button type="button" class="button" id="sglms-add-module">+ ' . esc_html__( 'Add Module', 'sglms' ) . '</button></p>';

		// Hidden template used by JS to clone new modules.
		echo '<script type="text/template" id="sglms-module-template">';
		$this->render_module(
			array(
				'id'      => '',
				'title'   => '',
				'lessons' => array(),
			),
			$lessons,
			'__INDEX__',
			true
		);
		echo '</script>';

		echo '</div>';

		$this->print_assets();
	}

	/**
	 * Render a single module block.
	 *
	 * @param array               $module  Module data.
	 * @param WP_Post[]           $lessons All lessons.
	 * @param string              $index   Field index (numeric or __INDEX__).
	 * @param bool                $is_template Whether this is the JS template.
	 * @return void
	 */
	private function render_module( $module, $lessons, $index, $is_template ) {
		$base       = 'sglms_module[' . $index . ']';
		$assigned   = isset( $module['lessons'] ) ? array_map( 'intval', $module['lessons'] ) : array();
		$module_id  = isset( $module['id'] ) ? $module['id'] : '';
		$module_ttl = isset( $module['title'] ) ? $module['title'] : '';

		echo '<div class="sglms-module" style="border:1px solid #c3c4c7;border-radius:6px;padding:12px;margin-bottom:12px;background:#fff;">';

		echo '<input type="hidden" name="' . esc_attr( $base ) . '[id]" value="' . esc_attr( $module_id ) . '" />';

		echo '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">';
		echo '<input type="text" class="regular-text" name="' . esc_attr( $base ) . '[title]" value="' . esc_attr( $module_ttl ) . '" placeholder="' . esc_attr__( 'Module title', 'sglms' ) . '" style="flex:1;" />';
		echo '<button type="button" class="button-link-delete sglms-remove-module" style="color:#b32d2e;">' . esc_html__( 'Remove', 'sglms' ) . '</button>';
		echo '</div>';

		if ( ! empty( $lessons ) ) {
			echo '<fieldset style="max-height:180px;overflow:auto;border:1px solid #dcdcde;border-radius:4px;padding:8px;">';
			echo '<legend class="screen-reader-text">' . esc_html__( 'Lessons in this module', 'sglms' ) . '</legend>';
			foreach ( $lessons as $lesson ) {
				$checked = in_array( (int) $lesson->ID, $assigned, true ) ? ' checked' : '';
				$status  = ( 'publish' !== $lesson->post_status ) ? ' (' . esc_html( $lesson->post_status ) . ')' : '';
				echo '<label style="display:block;padding:2px 0;">';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $checked is a hardcoded literal.
				echo '<input type="checkbox" name="' . esc_attr( $base ) . '[lessons][]" value="' . esc_attr( $lesson->ID ) . '"' . $checked . ' /> ';
				// $status is built from esc_html() above.
				echo esc_html( $lesson->post_title ) . $status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</label>';
			}
			echo '</fieldset>';
		}

		echo '</div>';
		unset( $is_template );
	}

	/**
	 * Save handler: validate request, then persist.
	 *
	 * @param int     $post_id Course ID.
	 * @param WP_Post $post    Course post.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$raw = isset( $_POST['sglms_module'] ) ? wp_unslash( $_POST['sglms_module'] ) : array();

		$this->persist( $post_id, is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Persist a modules payload to a course and sync reverse associations.
	 *
	 * Separated from save() so it is testable without a request context.
	 *
	 * @param int   $course_id Course ID.
	 * @param array $raw       Raw modules array (indexed; each: id,title,lessons[]).
	 * @return array The stored, sanitized modules.
	 */
	public function persist( $course_id, array $raw ) {
		// Re-index to a clean list and let the schema sanitize each entry.
		$payload = array_values( $raw );
		$stored  = Sglms_Modules_Schema::save( $course_id, $payload );

		$this->sync_lesson_associations( (int) $course_id, $stored );

		return $stored;
	}

	/**
	 * Maintain each lesson's reverse course/module association.
	 *
	 * Lessons present in this course's modules get _sglms_course_id and
	 * _sglms_module_id set. Lessons previously tied to this course but no
	 * longer present are detached.
	 *
	 * @param int   $course_id Course ID.
	 * @param array $modules   Sanitized modules.
	 * @return void
	 */
	private function sync_lesson_associations( $course_id, array $modules ) {
		$current = array();
		foreach ( $modules as $module ) {
			foreach ( $module['lessons'] as $lesson_id ) {
				$current[ (int) $lesson_id ] = $module['id'];
			}
		}

		// Detach lessons that used to belong to this course but were removed.
		$previously = get_posts(
			array(
				'post_type'   => 'sglms_lesson',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_key'    => '_sglms_course_id',   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'  => $course_id,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $previously as $lesson_id ) {
			if ( ! isset( $current[ (int) $lesson_id ] ) ) {
				delete_post_meta( $lesson_id, '_sglms_course_id' );
				delete_post_meta( $lesson_id, '_sglms_module_id' );
			}
		}

		// Attach/refresh current lessons.
		foreach ( $current as $lesson_id => $module_id ) {
			update_post_meta( $lesson_id, '_sglms_course_id', $course_id );
			update_post_meta( $lesson_id, '_sglms_module_id', $module_id );
		}
	}

	/**
	 * Inline CSS/JS for add/remove module behavior. No build step, no jQuery.
	 *
	 * @return void
	 */
	private function print_assets() {
		?>
		<script>
		( function () {
			var wrap = document.getElementById( 'sglms-modules' );
			var addBtn = document.getElementById( 'sglms-add-module' );
			var tpl = document.getElementById( 'sglms-module-template' );
			if ( ! wrap || ! addBtn || ! tpl ) { return; }

			function nextIndex() {
				var existing = wrap.querySelectorAll( '.sglms-module' ).length;
				// Use a high, monotonically increasing index to avoid collisions.
				return 'n' + ( Date.now().toString().slice( -6 ) ) + existing;
			}

			addBtn.addEventListener( 'click', function () {
				var html = tpl.innerHTML.replace( /__INDEX__/g, nextIndex() );
				var div = document.createElement( 'div' );
				div.innerHTML = html;
				while ( div.firstChild ) { wrap.appendChild( div.firstChild ); }
			} );

			wrap.addEventListener( 'click', function ( e ) {
				if ( e.target && e.target.classList.contains( 'sglms-remove-module' ) ) {
					var mod = e.target.closest( '.sglms-module' );
					if ( mod ) { mod.parentNode.removeChild( mod ); }
				}
			} );
		} )();
		</script>
		<?php
	}
}
