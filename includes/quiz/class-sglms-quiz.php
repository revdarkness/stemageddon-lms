<?php
/**
 * Quiz model: question schema, server-side grading, and attempts.
 *
 * SECURITY: correct answers live only in the `_sglms_questions` meta (never
 * REST-exposed) and are used only during server-side grading. The questions
 * sent to the browser are stripped of all correctness data by
 * public_questions(); grading happens here on submit. The client is never in
 * a position to know the answers.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Quiz
 */
class Sglms_Quiz {

	const META_QUESTIONS = '_sglms_questions';
	const TYPES          = array( 'mc', 'multi', 'tf', 'short', 'order' );

	/* --------------------------------------------------------------------- *
	 * Settings + questions
	 * --------------------------------------------------------------------- */

	/**
	 * Quiz settings with defaults.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array
	 */
	public static function get_settings( $quiz_id ) {
		$pass = get_post_meta( $quiz_id, '_sglms_pass_score', true );
		return array(
			'pass_score'    => '' === $pass ? 70 : (int) $pass,
			'attempt_limit' => (int) get_post_meta( $quiz_id, '_sglms_attempt_limit', true ),
			'randomize'     => (bool) get_post_meta( $quiz_id, '_sglms_randomize', true ),
			'course_id'     => (int) get_post_meta( $quiz_id, '_sglms_course_id', true ),
		);
	}

	/**
	 * Full question set (includes correct answers) — server use only.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array
	 */
	public static function get_questions( $quiz_id ) {
		$raw = get_post_meta( $quiz_id, self::META_QUESTIONS, true );
		return $raw ? self::sanitize_questions( $raw ) : array();
	}

	/**
	 * Question set for the browser: correctness stripped, options/order
	 * shuffled where appropriate. Safe to send to the client.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array
	 */
	public static function public_questions( $quiz_id ) {
		$questions = self::get_questions( $quiz_id );
		$settings  = self::get_settings( $quiz_id );

		$public = array();
		foreach ( $questions as $q ) {
			$item = array(
				'id'     => $q['id'],
				'type'   => $q['type'],
				'text'   => $q['text'],
				'points' => $q['points'],
			);

			if ( in_array( $q['type'], array( 'mc', 'multi', 'tf', 'order' ), true ) ) {
				$opts = array();
				foreach ( $q['options'] as $o ) {
					$opts[] = array(
						'id'   => $o['id'],
						'text' => $o['text'],
					);
				}
				// Ordering questions must be shuffled so the stored (correct)
				// sequence is not revealed by option order.
				if ( 'order' === $q['type'] ) {
					shuffle( $opts );
				}
				$item['options'] = $opts;
			}
			$public[] = $item;
		}

		if ( ! empty( $settings['randomize'] ) ) {
			shuffle( $public );
		}
		return $public;
	}

	/* --------------------------------------------------------------------- *
	 * Grading (server-side only)
	 * --------------------------------------------------------------------- */

	/**
	 * Grade a set of submitted answers.
	 *
	 * @param int   $quiz_id Quiz ID.
	 * @param array $answers Map of question id => submitted answer.
	 * @return array{score:int,max:int,percent:int,passed:bool,results:array}
	 */
	public static function grade( $quiz_id, $answers ) {
		$questions = self::get_questions( $quiz_id );
		$settings  = self::get_settings( $quiz_id );
		$answers   = is_array( $answers ) ? $answers : array();

		$score   = 0;
		$max     = 0;
		$results = array();

		foreach ( $questions as $q ) {
			$pts  = (int) $q['points'];
			$max += $pts;
			$sub  = isset( $answers[ $q['id'] ] ) ? $answers[ $q['id'] ] : null;

			$correct = self::is_correct( $q, $sub );
			if ( $correct ) {
				$score += $pts;
			}

			// Reveal only correctness + feedback — never the right answer.
			$results[] = array(
				'id'       => $q['id'],
				'correct'  => $correct,
				'feedback' => $q['feedback'],
			);
		}

		$percent = $max > 0 ? (int) round( $score / $max * 100 ) : 0;

		return array(
			'score'   => $score,
			'max'     => $max,
			'percent' => $percent,
			'passed'  => $percent >= $settings['pass_score'],
			'results' => $results,
		);
	}

	/**
	 * Whether a single submitted answer is correct.
	 *
	 * @param array $q   Question.
	 * @param mixed $sub Submitted answer.
	 * @return bool
	 */
	private static function is_correct( $q, $sub ) {
		switch ( $q['type'] ) {
			case 'mc':
			case 'tf':
				$correct_id = '';
				foreach ( $q['options'] as $o ) {
					if ( ! empty( $o['correct'] ) ) {
						$correct_id = $o['id'];
						break;
					}
				}
				return is_string( $sub ) && $sub === $correct_id;

			case 'multi':
				$correct_ids = array();
				foreach ( $q['options'] as $o ) {
					if ( ! empty( $o['correct'] ) ) {
						$correct_ids[] = $o['id'];
					}
				}
				$sub_ids = array_map( 'strval', (array) $sub );
				sort( $correct_ids );
				sort( $sub_ids );
				return ! empty( $correct_ids ) && $correct_ids === $sub_ids;

			case 'short':
				$norm = self::normalize( is_string( $sub ) ? $sub : '' );
				if ( '' === $norm ) {
					return false;
				}
				foreach ( $q['answers'] as $kw ) {
					$kw = self::normalize( $kw );
					if ( '' !== $kw && false !== strpos( $norm, $kw ) ) {
						return true;
					}
				}
				return false;

			case 'order':
				$correct_seq = array();
				foreach ( $q['options'] as $o ) {
					$correct_seq[] = $o['id'];
				}
				$sub_seq = array_map( 'strval', (array) $sub );
				return $correct_seq === $sub_seq;
		}
		return false;
	}

	/**
	 * Normalize text for keyword matching.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize( $text ) {
		return trim( strtolower( (string) $text ) );
	}

	/* --------------------------------------------------------------------- *
	 * Schema
	 * --------------------------------------------------------------------- */

	/**
	 * Coerce any payload into the strict question schema.
	 *
	 * @param array|string $raw Raw payload (array or JSON string).
	 * @return array
	 */
	public static function sanitize_questions( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();
		foreach ( $raw as $q ) {
			if ( ! is_array( $q ) || empty( $q['type'] ) || ! in_array( $q['type'], self::TYPES, true ) ) {
				continue;
			}

			$id = isset( $q['id'] ) ? sanitize_key( (string) $q['id'] ) : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = 'q_' . strtolower( wp_generate_password( 8, false, false ) );
			}
			$seen[ $id ] = true;

			$item = array(
				'id'       => $id,
				'type'     => $q['type'],
				'text'     => isset( $q['text'] ) ? wp_kses_post( (string) $q['text'] ) : '',
				'points'   => isset( $q['points'] ) ? max( 1, absint( $q['points'] ) ) : 1,
				'feedback' => isset( $q['feedback'] ) ? sanitize_text_field( (string) $q['feedback'] ) : '',
			);

			if ( 'short' === $q['type'] ) {
				$item['answers'] = array();
				if ( isset( $q['answers'] ) && is_array( $q['answers'] ) ) {
					foreach ( $q['answers'] as $kw ) {
						$kw = sanitize_text_field( (string) $kw );
						if ( '' !== $kw ) {
							$item['answers'][] = $kw;
						}
					}
				}
			} else {
				// mc / multi / tf / order all carry options.
				$item['options'] = array();
				$oseen           = array();
				if ( isset( $q['options'] ) && is_array( $q['options'] ) ) {
					foreach ( $q['options'] as $o ) {
						if ( ! is_array( $o ) ) {
							continue;
						}
						$oid = isset( $o['id'] ) ? sanitize_key( (string) $o['id'] ) : '';
						if ( '' === $oid || isset( $oseen[ $oid ] ) ) {
							$oid = 'o_' . strtolower( wp_generate_password( 6, false, false ) );
						}
						$oseen[ $oid ]     = true;
						$item['options'][] = array(
							'id'      => $oid,
							'text'    => isset( $o['text'] ) ? sanitize_text_field( (string) $o['text'] ) : '',
							// Ordering questions are stored in their CORRECT order;
							// correctness flags are irrelevant there.
							'correct' => ( 'order' === $q['type'] ) ? false : ! empty( $o['correct'] ),
						);
					}
				}
			}

			$clean[] = $item;
		}
		return $clean;
	}

	/* --------------------------------------------------------------------- *
	 * Attempts
	 * --------------------------------------------------------------------- */

	/**
	 * Attempts table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sglms_quiz_attempts';
	}

	/**
	 * Number of attempts a user has made on a quiz.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return int
	 */
	public static function count_attempts( $user_id, $quiz_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND quiz_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $quiz_id
			)
		);
	}

	/**
	 * Record a finished attempt.
	 *
	 * @param int   $user_id User ID.
	 * @param int   $quiz_id Quiz ID.
	 * @param array $result  Result from grade().
	 * @param array $answers Submitted answers (stored for review).
	 * @return void
	 */
	public static function record_attempt( $user_id, $quiz_id, $result, $answers ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::table(),
			array(
				'user_id'      => (int) $user_id,
				'quiz_id'      => (int) $quiz_id,
				'course_id'    => (int) get_post_meta( $quiz_id, '_sglms_course_id', true ),
				'score'        => (float) $result['score'],
				'max_score'    => (float) $result['max'],
				'passed'       => $result['passed'] ? 1 : 0,
				'answers_json' => wp_json_encode( $answers ),
				'started_at'   => $now,
				'finished_at'  => $now,
			),
			array( '%d', '%d', '%d', '%f', '%f', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Best (highest) percentage a user has scored on a quiz, or null.
	 *
	 * @param int $user_id User ID.
	 * @param int $quiz_id Quiz ID.
	 * @return int|null
	 */
	public static function best_percent( $user_id, $quiz_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT score, max_score FROM `{$table}` WHERE user_id = %d AND quiz_id = %d ORDER BY (score / NULLIF(max_score,0)) DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $quiz_id
			)
		);
		if ( ! $row || (float) $row->max_score <= 0 ) {
			return null;
		}
		return (int) round( (float) $row->score / (float) $row->max_score * 100 );
	}

	/**
	 * Attempt summary for a quiz (admin gradebook seed).
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array{attempts:int,users:int,passed:int,avg:int}
	 */
	public static function summary( $quiz_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) attempts, COUNT(DISTINCT user_id) users, SUM(passed) passed, AVG( score / NULLIF(max_score,0) ) avgratio FROM `{$table}` WHERE quiz_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $quiz_id
			)
		);
		return array(
			'attempts' => $row ? (int) $row->attempts : 0,
			'users'    => $row ? (int) $row->users : 0,
			'passed'   => $row ? (int) $row->passed : 0,
			'avg'      => ( $row && null !== $row->avgratio ) ? (int) round( (float) $row->avgratio * 100 ) : 0,
		);
	}
}
