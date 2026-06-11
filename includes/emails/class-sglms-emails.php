<?php
/**
 * Transactional emails.
 *
 * Sends templated, filterable notifications on enrollment and course
 * completion. Each type can be toggled in Settings. Subjects and bodies are
 * filterable (sglms_email_{type}_subject / _body) and support placeholder
 * tokens resolved per recipient.
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Sglms_Emails
 */
class Sglms_Emails {

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'sglms_user_enrolled', array( $this, 'on_enrolled' ), 20, 3 );
		add_action( 'sglms_course_completed', array( $this, 'on_completed' ), 20, 2 );
	}

	/**
	 * Whether a given email type is enabled.
	 *
	 * @param string $type Type key (enrollment|completion|instructor).
	 * @return bool
	 */
	public static function enabled( $type ) {
		$settings = get_option( 'sglms_settings', array() );
		$key      = 'email_' . $type;
		// Default on when unset.
		return ! isset( $settings[ $key ] ) || ! empty( $settings[ $key ] );
	}

	/**
	 * Fire enrollment emails.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $course_id Course ID.
	 * @param string $source    Source.
	 * @return void
	 */
	public function on_enrolled( $user_id, $course_id, $source = '' ) {
		unset( $source );
		if ( self::enabled( 'enrollment' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && $user->user_email ) {
				$mail = $this->build_enrollment( $user_id, $course_id );
				$this->send( $user->user_email, $mail['subject'], $mail['body'] );
			}
		}

		if ( self::enabled( 'instructor' ) ) {
			$author = (int) get_post_field( 'post_author', $course_id );
			$inst   = $author ? get_userdata( $author ) : null;
			if ( $inst && $inst->user_email ) {
				$mail = $this->build_instructor( $user_id, $course_id );
				$this->send( $inst->user_email, $mail['subject'], $mail['body'] );
			}
		}
	}

	/**
	 * Fire the completion email.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return void
	 */
	public function on_completed( $user_id, $course_id ) {
		if ( ! self::enabled( 'completion' ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return;
		}
		$mail = $this->build_completion( $user_id, $course_id );
		$this->send( $user->user_email, $mail['subject'], $mail['body'] );
	}

	/* --------------------------------------------------------------------- *
	 * Builders (return [subject, body]) — testable without sending.
	 * --------------------------------------------------------------------- */

	/**
	 * Build the enrollment email.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array{subject:string,body:string}
	 */
	public function build_enrollment( $user_id, $course_id ) {
		$tokens  = $this->tokens( $user_id, $course_id );
		$subject = sprintf( /* translators: %s: course title. */ __( 'You are enrolled in %s', 'sglms' ), $tokens['{course}'] );
		$body    = sprintf(
			/* translators: 1: name, 2: course, 3: course URL. */
			__( "Hi %1\$s,\n\nYou are now enrolled in \"%2\$s\". You can start any time here:\n%3\$s\n\nHappy learning!", 'sglms' ),
			$tokens['{name}'],
			$tokens['{course}'],
			$tokens['{course_url}']
		);
		return $this->finish( 'enrollment', $subject, $body, $tokens );
	}

	/**
	 * Build the completion email (includes the certificate link if present).
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array{subject:string,body:string}
	 */
	public function build_completion( $user_id, $course_id ) {
		$tokens    = $this->tokens( $user_id, $course_id );
		$cert      = Sglms_Certificate::get_active( $user_id, $course_id );
		$cert_line = '';
		if ( $cert ) {
			$cert_line = "\n\n" . sprintf(
				/* translators: %s: certificate download URL. */
				__( 'Download your certificate:\n%s', 'sglms' ),
				Sglms_Certificate::download_url( $cert->cert_uid )
			);
		}
		$subject = sprintf( /* translators: %s: course title. */ __( 'Congratulations — you completed %s', 'sglms' ), $tokens['{course}'] );
		$body    = sprintf(
			/* translators: 1: name, 2: course. */
			__( "Hi %1\$s,\n\nYou have completed \"%2\$s\". Well done!", 'sglms' ),
			$tokens['{name}'],
			$tokens['{course}']
		) . $cert_line;
		return $this->finish( 'completion', $subject, $body, $tokens );
	}

	/**
	 * Build the instructor notification.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array{subject:string,body:string}
	 */
	public function build_instructor( $user_id, $course_id ) {
		$tokens  = $this->tokens( $user_id, $course_id );
		$subject = sprintf( /* translators: %s: course title. */ __( 'New enrollment in %s', 'sglms' ), $tokens['{course}'] );
		$body    = sprintf(
			/* translators: 1: student name, 2: course. */
			__( '%1$s just enrolled in your course "%2$s".', 'sglms' ),
			$tokens['{name}'],
			$tokens['{course}']
		);
		return $this->finish( 'instructor', $subject, $body, $tokens );
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Placeholder tokens for a recipient/course.
	 *
	 * @param int $user_id   User ID.
	 * @param int $course_id Course ID.
	 * @return array<string,string>
	 */
	private function tokens( $user_id, $course_id ) {
		$user = get_userdata( $user_id );
		return array(
			'{name}'       => $user ? $user->display_name : '',
			'{course}'     => get_the_title( $course_id ),
			'{course_url}' => get_permalink( $course_id ),
			'{site}'       => get_bloginfo( 'name' ),
			'{login_url}'  => wp_login_url(),
		);
	}

	/**
	 * Apply filters and token replacement.
	 *
	 * @param string                $type    Type key.
	 * @param string                $subject Subject.
	 * @param string                $body    Body.
	 * @param array<string,string>  $tokens  Tokens.
	 * @return array{subject:string,body:string}
	 */
	private function finish( $type, $subject, $body, $tokens ) {
		/** Filter the subject for an email type. */
		$subject = apply_filters( "sglms_email_{$type}_subject", $subject, $tokens );
		/** Filter the body for an email type. */
		$body = apply_filters( "sglms_email_{$type}_body", $body, $tokens );

		$subject = strtr( $subject, $tokens );
		$body    = strtr( $body, $tokens );
		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * Send a plain-text email.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return void
	 */
	private function send( $to, $subject, $body ) {
		wp_mail( $to, $subject, $body );
	}
}
