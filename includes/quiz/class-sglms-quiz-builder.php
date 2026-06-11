<?php
/**
 * Quiz builder meta boxes.
 *
 * "Quiz Settings" (pass score, attempts, randomize, required, course) and
 * "Questions" (add questions of any supported type, with options/keywords,
 * correct flags, points, and feedback). Server-rendered with inline vanilla
 * JS for add/remove; saves through the registered meta sanitizers.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Quiz_Builder
 */
class Sglms_Quiz_Builder {

	const NONCE_ACTION = 'sglms_save_quiz';
	const NONCE_NAME   = 'sglms_quiz_nonce';

	/**
	 * Hook meta boxes and save.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_sglms_quiz', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_sglms_quiz', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta boxes.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box( 'sglms_quiz_settings', __( 'Quiz Settings', 'sglms' ), array( $this, 'render_settings' ), 'sglms_quiz', 'side' );
		add_meta_box( 'sglms_quiz_questions', __( 'Questions', 'sglms' ), array( $this, 'render_questions' ), 'sglms_quiz', 'normal', 'high' );
		add_meta_box( 'sglms_quiz_results', __( 'Results', 'sglms' ), array( $this, 'render_results' ), 'sglms_quiz', 'side', 'low' );
	}

	/**
	 * Settings panel.
	 *
	 * @param WP_Post $post Quiz.
	 * @return void
	 */
	public function render_settings( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$s = Sglms_Quiz::get_settings( $post->ID );

		$courses = get_posts(
			array(
				'post_type'   => 'sglms_course',
				'post_status' => array( 'publish', 'draft', 'private' ),
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		echo '<p><label>' . esc_html__( 'Passing score (%)', 'sglms' ) . '<br />';
		echo '<input type="number" min="0" max="100" name="sglms_pass_score" value="' . esc_attr( $s['pass_score'] ) . '" /></label></p>';

		echo '<p><label>' . esc_html__( 'Attempt limit (0 = unlimited)', 'sglms' ) . '<br />';
		echo '<input type="number" min="0" name="sglms_attempt_limit" value="' . esc_attr( $s['attempt_limit'] ) . '" /></label></p>';

		echo '<p><label><input type="checkbox" name="sglms_randomize" value="1" ' . checked( $s['randomize'], true, false ) . ' /> ' . esc_html__( 'Randomize question order', 'sglms' ) . '</label></p>';

		$required = get_post_meta( $post->ID, '_sglms_required', true );
		echo '<p><label><input type="checkbox" name="sglms_required" value="1" ' . checked( '' === $required || $required, true, false ) . ' /> ' . esc_html__( 'Required to complete the course', 'sglms' ) . '</label></p>';

		echo '<p><label>' . esc_html__( 'Belongs to course', 'sglms' ) . '<br /><select name="sglms_course_id" style="max-width:100%;">';
		echo '<option value="0">' . esc_html__( '— None —', 'sglms' ) . '</option>';
		foreach ( $courses as $c ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $c->ID, selected( $s['course_id'], (int) $c->ID, false ), esc_html( $c->post_title ) );
		}
		echo '</select></label></p>';
	}

	/**
	 * Read-only attempt summary.
	 *
	 * @param WP_Post $post Quiz.
	 * @return void
	 */
	public function render_results( $post ) {
		$sum = Sglms_Quiz::summary( $post->ID );
		echo '<ul style="margin:0;">';
		echo '<li>' . esc_html( sprintf( /* translators: %d: count. */ __( 'Attempts: %d', 'sglms' ), $sum['attempts'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: %d: count. */ __( 'Distinct learners: %d', 'sglms' ), $sum['users'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: %d: count. */ __( 'Passing attempts: %d', 'sglms' ), $sum['passed'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: %d: percent. */ __( 'Average score: %d%%', 'sglms' ), $sum['avg'] ) ) . '</li>';
		echo '</ul>';
	}

	/**
	 * Questions editor.
	 *
	 * @param WP_Post $post Quiz.
	 * @return void
	 */
	public function render_questions( $post ) {
		$questions = Sglms_Quiz::get_questions( $post->ID );

		echo '<div class="sglms-qbuilder">';
		echo '<p class="description">' . esc_html__( 'For Multiple choice and True/False, mark exactly one correct option. For Ordering, list options in the correct order (they are shuffled for the learner).', 'sglms' ) . '</p>';

		echo '<div id="sglms-questions">';
		if ( empty( $questions ) ) {
			$this->render_question( array(), '0' );
		} else {
			foreach ( $questions as $i => $q ) {
				$this->render_question( $q, (string) $i );
			}
		}
		echo '</div>';

		echo '<p><button type="button" class="button" id="sglms-add-question">+ ' . esc_html__( 'Add Question', 'sglms' ) . '</button></p>';

		// JS templates.
		echo '<script type="text/template" id="sglms-question-tpl">';
		$this->render_question( array(), '__QI__' );
		echo '</script>';
		echo '<script type="text/template" id="sglms-option-tpl">';
		$this->render_option( array(), '__QI__', '__OI__' );
		echo '</script>';

		echo '</div>';
		$this->print_assets();
	}

	/**
	 * Render one question block.
	 *
	 * @param array  $q  Question data (may be empty).
	 * @param string $qi Question index token.
	 * @return void
	 */
	private function render_question( $q, $qi ) {
		$base     = 'sglms_q[' . $qi . ']';
		$type     = isset( $q['type'] ) ? $q['type'] : 'mc';
		$text     = isset( $q['text'] ) ? $q['text'] : '';
		$points   = isset( $q['points'] ) ? (int) $q['points'] : 1;
		$feedback = isset( $q['feedback'] ) ? $q['feedback'] : '';
		$qid      = isset( $q['id'] ) ? $q['id'] : '';
		$answers  = ( isset( $q['answers'] ) && is_array( $q['answers'] ) ) ? implode( "\n", $q['answers'] ) : '';

		$types = array(
			'mc'    => __( 'Multiple choice', 'sglms' ),
			'multi' => __( 'Multiple select', 'sglms' ),
			'tf'    => __( 'True / False', 'sglms' ),
			'short' => __( 'Short answer', 'sglms' ),
			'order' => __( 'Ordering', 'sglms' ),
		);

		echo '<div class="sglms-question" style="border:1px solid #c3c4c7;border-radius:6px;padding:12px;margin-bottom:12px;background:#fff;">';
		echo '<input type="hidden" name="' . esc_attr( $base ) . '[id]" value="' . esc_attr( $qid ) . '" />';

		echo '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">';
		echo '<select name="' . esc_attr( $base ) . '[type]" class="sglms-qtype">';
		foreach ( $types as $val => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $val ), selected( $type, $val, false ), esc_html( $label ) );
		}
		echo '</select>';
		echo '<input type="number" min="1" name="' . esc_attr( $base ) . '[points]" value="' . esc_attr( $points ) . '" title="' . esc_attr__( 'Points', 'sglms' ) . '" style="width:70px;" />';
		echo '<button type="button" class="button-link-delete sglms-remove-question" style="color:#b32d2e;margin-left:auto;">' . esc_html__( 'Remove', 'sglms' ) . '</button>';
		echo '</div>';

		echo '<p><textarea name="' . esc_attr( $base ) . '[text]" rows="2" style="width:100%;" placeholder="' . esc_attr__( 'Question text', 'sglms' ) . '">' . esc_textarea( $text ) . '</textarea></p>';

		// Options group (mc/multi/tf/order).
		$show_opts = ( 'short' !== $type );
		echo '<div class="sglms-q-options" ' . ( $show_opts ? '' : 'hidden' ) . '>';
		echo '<div class="sglms-options-list">';
		if ( ! empty( $q['options'] ) && is_array( $q['options'] ) ) {
			foreach ( $q['options'] as $oi => $o ) {
				$this->render_option( $o, $qi, (string) $oi );
			}
		} else {
			$this->render_option( array(), $qi, '0' );
			$this->render_option( array(), $qi, '1' );
		}
		echo '</div>';
		echo '<button type="button" class="button sglms-add-option">+ ' . esc_html__( 'Add Option', 'sglms' ) . '</button>';
		echo '</div>';

		// Short-answer group.
		echo '<div class="sglms-q-answers" ' . ( $show_opts ? 'hidden' : '' ) . '>';
		echo '<p><label>' . esc_html__( 'Accepted answers (one keyword/phrase per line; case-insensitive substring match)', 'sglms' ) . '<br />';
		echo '<textarea name="' . esc_attr( $base ) . '[answers]" rows="3" style="width:100%;">' . esc_textarea( $answers ) . '</textarea></label></p>';
		echo '</div>';

		echo '<p><input type="text" name="' . esc_attr( $base ) . '[feedback]" value="' . esc_attr( $feedback ) . '" style="width:100%;" placeholder="' . esc_attr__( 'Feedback shown after answering (optional)', 'sglms' ) . '" /></p>';

		echo '</div>';
	}

	/**
	 * Render one option row.
	 *
	 * @param array  $o  Option data.
	 * @param string $qi Question index token.
	 * @param string $oi Option index token.
	 * @return void
	 */
	private function render_option( $o, $qi, $oi ) {
		$base    = 'sglms_q[' . $qi . '][options][' . $oi . ']';
		$oid     = isset( $o['id'] ) ? $o['id'] : '';
		$otext   = isset( $o['text'] ) ? $o['text'] : '';
		$correct = ! empty( $o['correct'] );

		echo '<div class="sglms-option" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">';
		echo '<input type="hidden" name="' . esc_attr( $base ) . '[id]" value="' . esc_attr( $oid ) . '" />';
		echo '<input type="text" name="' . esc_attr( $base ) . '[text]" value="' . esc_attr( $otext ) . '" placeholder="' . esc_attr__( 'Option', 'sglms' ) . '" style="flex:1;" />';
		echo '<label class="sglms-correct-label" title="' . esc_attr__( 'Correct', 'sglms' ) . '"><input type="checkbox" name="' . esc_attr( $base ) . '[correct]" value="1" ' . checked( $correct, true, false ) . ' /> ' . esc_html__( 'Correct', 'sglms' ) . '</label>';
		echo '<button type="button" class="button-link-delete sglms-remove-option" style="color:#b32d2e;">&times;</button>';
		echo '</div>';
	}

	/**
	 * Save handler.
	 *
	 * @param int     $post_id Quiz ID.
	 * @param WP_Post $post    Quiz.
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
		$raw       = isset( $_POST['sglms_q'] ) ? wp_unslash( $_POST['sglms_q'] ) : array();
		$questions = array();
		foreach ( (array) $raw as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$type  = isset( $q['type'] ) ? sanitize_key( $q['type'] ) : 'mc';
			$entry = array(
				'id'       => isset( $q['id'] ) ? $q['id'] : '',
				'type'     => $type,
				'text'     => isset( $q['text'] ) ? $q['text'] : '',
				'points'   => isset( $q['points'] ) ? $q['points'] : 1,
				'feedback' => isset( $q['feedback'] ) ? $q['feedback'] : '',
			);
			if ( 'short' === $type ) {
				$entry['answers'] = isset( $q['answers'] ) ? preg_split( '/\r\n|\r|\n/', (string) $q['answers'] ) : array();
			} else {
				$entry['options'] = array();
				if ( isset( $q['options'] ) && is_array( $q['options'] ) ) {
					foreach ( $q['options'] as $o ) {
						if ( ! is_array( $o ) ) {
							continue;
						}
						// Skip blank option rows.
						if ( '' === trim( (string) ( $o['text'] ?? '' ) ) ) {
							continue;
						}
						$entry['options'][] = array(
							'id'      => isset( $o['id'] ) ? $o['id'] : '',
							'text'    => isset( $o['text'] ) ? $o['text'] : '',
							'correct' => ! empty( $o['correct'] ),
						);
					}
				}
			}
			$questions[] = $entry;
		}

		// Sanitize callback re-validates and JSON-encodes.
		update_post_meta( $post_id, '_sglms_questions', $questions );

		update_post_meta( $post_id, '_sglms_pass_score', isset( $_POST['sglms_pass_score'] ) ? absint( wp_unslash( $_POST['sglms_pass_score'] ) ) : 70 );
		update_post_meta( $post_id, '_sglms_attempt_limit', isset( $_POST['sglms_attempt_limit'] ) ? absint( wp_unslash( $_POST['sglms_attempt_limit'] ) ) : 0 );
		update_post_meta( $post_id, '_sglms_randomize', isset( $_POST['sglms_randomize'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_sglms_required', isset( $_POST['sglms_required'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_sglms_course_id', isset( $_POST['sglms_course_id'] ) ? absint( wp_unslash( $_POST['sglms_course_id'] ) ) : 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Inline builder JS.
	 *
	 * @return void
	 */
	private function print_assets() {
		?>
		<script>
		( function () {
			var wrap = document.getElementById( 'sglms-questions' );
			var addQ = document.getElementById( 'sglms-add-question' );
			var qTpl = document.getElementById( 'sglms-question-tpl' );
			var oTpl = document.getElementById( 'sglms-option-tpl' );
			if ( ! wrap || ! addQ ) { return; }

			function uid() { return ( Date.now().toString().slice( -6 ) ) + Math.floor( Math.random() * 1000 ); }

			function toggleType( q ) {
				var sel = q.querySelector( '.sglms-qtype' );
				if ( ! sel ) { return; }
				var isShort = sel.value === 'short';
				var opts = q.querySelector( '.sglms-q-options' );
				var ans = q.querySelector( '.sglms-q-answers' );
				if ( opts ) { opts.hidden = isShort; }
				if ( ans ) { ans.hidden = ! isShort; }
			}

			addQ.addEventListener( 'click', function () {
				var html = qTpl.innerHTML.replace( /__QI__/g, 'q' + uid() );
				var div = document.createElement( 'div' );
				div.innerHTML = html;
				while ( div.firstChild ) { wrap.appendChild( div.firstChild ); }
			} );

			wrap.addEventListener( 'change', function ( e ) {
				if ( e.target.classList.contains( 'sglms-qtype' ) ) {
					toggleType( e.target.closest( '.sglms-question' ) );
				}
			} );

			wrap.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'sglms-remove-question' ) ) {
					var q = e.target.closest( '.sglms-question' );
					if ( q ) { q.remove(); }
				}
				if ( e.target.classList.contains( 'sglms-remove-option' ) ) {
					var o = e.target.closest( '.sglms-option' );
					if ( o ) { o.remove(); }
				}
				if ( e.target.classList.contains( 'sglms-add-option' ) ) {
					var q2 = e.target.closest( '.sglms-question' );
					var list = q2.querySelector( '.sglms-options-list' );
					var qiInput = q2.querySelector( 'input[name^="sglms_q["]' );
					var qi = qiInput ? qiInput.getAttribute( 'name' ).match( /sglms_q\[([^\]]+)\]/ )[ 1 ] : 'q' + uid();
					var html = oTpl.innerHTML.replace( /__QI__/g, qi ).replace( /__OI__/g, 'o' + uid() );
					var div = document.createElement( 'div' );
					div.innerHTML = html;
					while ( div.firstChild ) { list.appendChild( div.firstChild ); }
				}
			} );

			// Initialize visibility for existing questions.
			wrap.querySelectorAll( '.sglms-question' ).forEach( toggleType );
		} )();
		</script>
		<?php
	}
}
