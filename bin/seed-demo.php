<?php
/**
 * Demo content seeder (dev only — excluded from the distribution zip).
 *
 * Run with WP-CLI:
 *   wp eval-file wp-content/plugins/stemageddon-lms/bin/seed-demo.php
 *
 * Idempotent: re-running deletes the prior demo set (anything tagged with the
 * _sglms_demo meta) and rebuilds it. Creates one free course with two modules
 * and three lessons (one with a video and a downloadable resource), a required
 * quiz, a certificate template, and a student account (student / student).
 *
 * @package Sglms
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/** Mark a post as demo content for clean re-seeding. */
function sglms_demo_flag( $post_id ) {
	update_post_meta( $post_id, '_sglms_demo', 1 );
	return $post_id;
}

// 1. Tear down any prior demo content.
$prior = get_posts(
	array(
		'post_type'   => array( 'sglms_course', 'sglms_lesson', 'sglms_quiz', 'sglms_cert_tpl', 'attachment' ),
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_key'    => '_sglms_demo', // phpcs:ignore
		'meta_value'  => 1,             // phpcs:ignore
	)
);
foreach ( $prior as $pid ) {
	wp_delete_post( $pid, true );
}

// 2. Certificate template.
$tpl = sglms_demo_flag(
	wp_insert_post(
		array(
			'post_type'   => 'sglms_cert_tpl',
			'post_title'  => 'STEMageddon Certificate',
			'post_status' => 'publish',
		)
	)
);

// 3. Course.
$course = sglms_demo_flag(
	wp_insert_post(
		array(
			'post_type'    => 'sglms_course',
			'post_title'   => 'Intro to Model Rocketry',
			'post_status'  => 'publish',
			'post_content' => 'Build, launch, and recover your first model rocket. This short course covers safety, aerodynamics, and a hands-on build.',
		)
	)
);
update_post_meta( $course, '_sglms_price_mode', 'free' );
update_post_meta( $course, '_sglms_est_duration', '2 hours' );
update_post_meta( $course, '_sglms_certificate_tpl_id', $tpl );
wp_set_object_terms( $course, 'Beginner', 'sglms_difficulty' );

// 4. Lessons.
$l1 = sglms_demo_flag(
	wp_insert_post(
		array(
			'post_type'    => 'sglms_lesson',
			'post_title'   => 'Rocket Safety & Range Rules',
			'post_status'  => 'publish',
			'post_content' => '<p>Before anything flies, we cover the NAR safety code: launch angles, recovery, and clear ranges.</p>',
		)
	)
);
update_post_meta( $l1, '_sglms_video', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );

$l2 = sglms_demo_flag(
	wp_insert_post(
		array(
			'post_type'    => 'sglms_lesson',
			'post_title'   => 'Aerodynamics & Stability',
			'post_status'  => 'publish',
			'post_content' => '<p>Center of pressure vs. center of gravity, fin design, and the swing test.</p>',
		)
	)
);

$l3 = sglms_demo_flag(
	wp_insert_post(
		array(
			'post_type'    => 'sglms_lesson',
			'post_title'   => 'Your First Build',
			'post_status'  => 'publish',
			'post_content' => '<p>Step-by-step assembly, engine mounting, and pre-flight checklist.</p>',
		)
	)
);

// 5. A downloadable resource on lesson 1 (real file in uploads).
$uploads = wp_upload_dir();
$demo_dir = trailingslashit( $uploads['basedir'] ) . 'sglms-demo';
if ( ! is_dir( $demo_dir ) ) {
	wp_mkdir_p( $demo_dir );
}
$file = $demo_dir . '/range-checklist.txt';
file_put_contents( $file, "STEMageddon Range Safety Checklist\n\n[ ] Recovery system packed\n[ ] Launch angle <= 30deg\n[ ] Range clear\n[ ] Countdown audible\n" ); // phpcs:ignore
$attach = sglms_demo_flag(
	wp_insert_attachment(
		array(
			'post_title'     => 'Range Safety Checklist',
			'post_mime_type' => 'text/plain',
			'post_status'    => 'inherit',
		),
		$file,
		$l1
	)
);
update_post_meta( $l1, '_sglms_attachments', wp_json_encode( array( $attach ) ) );

// 6. Curriculum: two modules.
( new Sglms_Course_Builder() )->persist(
	$course,
	array(
		array( 'id' => '', 'title' => 'Module 1 — Ground School', 'lessons' => array( $l1, $l2 ) ),
		array( 'id' => '', 'title' => 'Module 2 — Build & Fly', 'lessons' => array( $l3 ) ),
	)
);

// 7. Quiz (required, attached to the course).
$quiz = sglms_demo_flag(
	wp_insert_post(
		array(
			'post_type'    => 'sglms_quiz',
			'post_title'   => 'Rocketry Basics Check',
			'post_status'  => 'publish',
			'post_content' => '<p>A quick check before you build. 60% to pass.</p>',
		)
	)
);
update_post_meta( $quiz, '_sglms_course_id', $course );
update_post_meta( $quiz, '_sglms_pass_score', 60 );
update_post_meta( $quiz, '_sglms_required', 1 );
update_post_meta(
	$quiz,
	'_sglms_questions',
	array(
		array(
			'id'      => 'qsafe',
			'type'    => 'mc',
			'text'    => 'What is the maximum recommended launch angle from vertical?',
			'points'  => 1,
			'options' => array(
				array( 'id' => 'a', 'text' => '30 degrees', 'correct' => true ),
				array( 'id' => 'b', 'text' => '60 degrees', 'correct' => false ),
				array( 'id' => 'c', 'text' => '90 degrees', 'correct' => false ),
			),
		),
		array(
			'id'      => 'qstab',
			'type'    => 'tf',
			'text'    => 'A stable rocket has its center of gravity ahead of its center of pressure.',
			'points'  => 1,
			'options' => array(
				array( 'id' => 't', 'text' => 'True', 'correct' => true ),
				array( 'id' => 'f', 'text' => 'False', 'correct' => false ),
			),
		),
		array(
			'id'      => 'qkw',
			'type'    => 'short',
			'text'    => 'Name the test that checks stability by spinning the rocket on a string.',
			'points'  => 1,
			'answers' => array( 'swing' ),
		),
	)
);

// 8. Student account.
$student = get_user_by( 'login', 'student' );
if ( ! $student ) {
	$sid = wp_insert_user(
		array(
			'user_login'   => 'student',
			'user_pass'    => 'student',
			'user_email'   => 'student@example.test',
			'display_name' => 'Demo Student',
			'role'         => 'sglms_student',
		)
	);
} else {
	$sid = $student->ID;
}

WP_CLI::success( 'Demo seeded.' );
WP_CLI::log( 'Course #' . $course . ' "Intro to Model Rocketry" (free) — lessons ' . $l1 . '/' . $l2 . '/' . $l3 . ', quiz ' . $quiz . ', cert tpl ' . $tpl . '.' );
WP_CLI::log( 'Course URL: ' . get_permalink( $course ) );
WP_CLI::log( 'Student login: student / student (user #' . $sid . ').' );
