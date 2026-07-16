=== STEMageddon LMS ===
Contributors: stemageddon
Tags: lms, courses, quizzes, certificates, membership, e-learning
Requires at least: 6.4
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-contained, Coursera-style learning management system. Courses, quizzes, certificates, protected downloads, and membership access control with zero paid dependencies.

== Description ==

STEMageddon LMS is a portable learning management system that installs on any WordPress 6.4+ site. It ships everything it needs inside the plugin: no external SaaS, no required Composer install at runtime, no paid add-ons.

Core capabilities (built across phased releases):

* Courses organized as Course > Modules > Lessons with a drag-and-drop builder.
* Lessons supporting rich text, video, audio, downloadable attachments, and embeds.
* A quiz engine with multiple choice, multiple select, true/false, short answer, and ordering questions.
* Per-user progress tracking, completion detection, and drip content.
* Membership access control: protect any page, post, or course for logged-in members or specific enrollees.
* Server-side PDF certificate generation with a public verification endpoint.
* Tokenized, enrollment-gated file downloads with a download log.
* A Coursera-parity front end: catalog, course landing, and a lesson player with a curriculum sidebar.

Version 1.0.0 is the first complete release: the full content model, access control, enrollment and progress, the Coursera-style front end, quizzes, certificates, protected downloads, reporting, emails, settings, and an optional WooCommerce bridge. Updates are delivered from GitHub releases, so the same plugin can be attached to many WordPress installs and updated from each site's normal Updates screen.

== Installation ==

1. Upload the `stemageddon-lms` folder to `/wp-content/plugins/`, or install the .zip via Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins screen in WordPress.
3. On activation the plugin creates the LMS database tables, the `LMS Student` and `LMS Instructor` roles, and three pages (My Learning, Course Catalog, Access Denied).

= Nginx note for protected downloads =

The plugin writes an Apache `.htaccess` deny rule into `wp-content/uploads/sglms-protected/`. On nginx, add an equivalent rule to your server block to deny direct access, since `.htaccess` is ignored:

    location ^~ /wp-content/uploads/sglms-protected/ {
        deny all;
        return 403;
    }

Protected files are always served through the plugin's tokenized download controller, which checks enrollment before streaming.

== Uninstall ==

Uninstalling is non-destructive by default. To remove all LMS data (tables, options, roles, pages, and the protected uploads directory) when the plugin is deleted, enable "Delete all data on uninstall" in the LMS settings first. Deactivation never removes data.

== Changelog ==

= 1.0.2 =
* Course progress now counts required quizzes, not just lessons. The progress bar can no longer reach 100% until every required quiz is passed, so a learner who watched all the lessons but skipped the quiz is not falsely shown as finished. The student dashboard flags an outstanding quiz ("quiz required") and its action button reads "Quiz required to finish" instead of a bare "Continue", making the missing step explicit (and explaining why no certificate has issued yet). Bug fix and UX only; no schema or data changes.

= 1.0.1 =
* Completed the dark "lab bench HUD" reskin across the remaining front-end screens. The student dashboard ([sglms_dashboard]) and the access-denied page now use the design-system tokens and components (HUD course-row cards with glowing progress bars, blueprint-grid placeholders, a styled empty state, and a centered restricted-content panel) instead of the previous inline default styling, matching the already-reskinned catalog, course landing, lesson player, and quiz surfaces. Added a developer-only demo content seeder (bin/seed-demo.php), excluded from the distribution build. Styling and tooling only; no data-model or behavior changes.

= 1.0.0 =
* First complete release (Phase 9 packaging). Vendored, self-hosted update checker (MIT) pointed at GitHub releases, with an sglms_github_auth_token filter for private repos. Build script produces a clean installable zip. Full PHPCS WordPress-Extra pass (0 errors). Smoke-tested on a block theme; portable to classic themes.

= 0.9.0 =
* Phase 8 reports, emails, settings, and WooCommerce. Reports screen with headline totals, per-course completion rates, and quiz score stats, each exportable as CSV. Transactional emails (filterable, individually toggleable): enrollment confirmation, course completion with certificate link, and instructor new-enrollment notice. Settings screen (Settings API) for page assignments, the download whitelist, email toggles, the default certificate template, and the destructive-uninstall opt-in. Course Settings meta box (enrollment mode, estimated duration, WooCommerce product mapping, certificate template). WooCommerce bridge: when active, a completed/processing order enrolls the buyer in mapped courses and the enroll CTA points at the product, via the payment adapter interface.

= 0.8.0 =
* Phase 7 protected downloads. Course and lesson resources are delivered only through short-lived (15-minute), HMAC-signed, per-user download tokens at /sglms-download/{token}; the controller verifies the token, the user, and enrollment before streaming, so raw media URLs cannot be shared. Resources / Downloads meta box on courses and lessons (media-library multi-select). "Download all" builds a course resource zip on the fly (ZipArchive). Configurable file-type whitelist enforced on every stream. Downloads are logged with a hashed IP. The lesson player Resources tab and an enrolled-only Course Resources section now use tokenized links. Rewrite flushing is coordinated centrally so certificate and download pretty URLs resolve after activation or upgrade.

= 0.7.0 =
* Phase 6 certificates. Server-side PDF generation via the vendored, GPL-compatible FPDF library (no Composer, no external services). Certificate template builder: pick a background image and drag dynamic fields (student name, course title, completion date, certificate ID, verification URL, signature image) into position over a live preview; positions stored as page percentages with per-field font, size, color, and alignment. Auto-issues on course completion (hooks sglms_course_completed) with a non-sequential 20-character verification ID. Public verification page at /verify-certificate/{id} (standalone, theme-independent) plus a public REST verify route. Owner-checked PDF downloads streamed from the protected uploads directory. Dashboard certificate downloads and admin issue / regenerate / revoke actions on the enrollment roster. (QR code on the PDF is planned for a later release; the verification URL is printed as text for now.)

= 0.6.0 =
* Phase 5 quizzes. Quiz engine supporting multiple choice, multiple select, true/false, short answer (case-insensitive keyword match), and ordering questions, with configurable passing score, attempt limits, randomized order, points, and per-question feedback. Quiz builder meta boxes (settings + question editor) and a read-only results summary. REST start/submit routes: correct answers are never sent to the client and grading is entirely server-side. Front-end quiz runner (answer-stripped questions from /start, ordering up/down UI, server-graded results with feedback). Passing a required quiz contributes to course completion.

= 0.5.0 =
* Phase 4 front end. Course catalog ([sglms_catalog]) with search, category, and difficulty filters. Course landing page (hero, facts, enroll/continue CTA, syllabus accordion, instructor bio) rendered via the_content. Coursera-style lesson player: curriculum sidebar with completion checkmarks and drip locks, video embed, Lesson/Resources/Notes tabs, mark-complete wired to REST, prev/next navigation, and a course progress bar. Per-user private notes (REST GET/PUT). Lesson comments scoped to enrolled users. Scoped, prefixed CSS and dependency-free vanilla JS (no jQuery); rendering uses the_content filters rather than template takeover, keeping the plugin portable across classic and block themes.

= 0.4.0 =
* Phase 3 enrollment and progress. Enrollment manager with states (enrolled / completed / revoked), manual admin enrollment, and free self-enrollment; answers the gatekeeper's course-access filter so enrolled users gain access. Progress tracker records lesson completion, computes course progress against required lessons, and detects course completion (all required lessons done + required quizzes passed), firing sglms_course_completed. Drip engine (sequential prerequisite and enrollment-relative schedule). Payment adapter interface for the Phase 8 WooCommerce bridge. REST routes: self-enroll, mark lesson complete, course progress. Admin Enrollments screen (manual enroll, roster, revoke). Student dashboard via [sglms_dashboard] with progress bars.

= 0.3.0 =
* Phase 2 access control. Gatekeeper enforces a single can_access() authority and prevents protected content leaking via direct URL (redirect to the access-denied page, block-theme safe), the_content, excerpts, search, feeds, and REST. Content Protection meta box on every public post type (none / members / course / level). [sglms_protect] shortcode plus [sglms_access_denied], catalog, and dashboard placeholders. Theme-overridable access-denied template with login and enroll CTAs. Instructors confined to their own courses via an own-content capability set and a map_meta_cap ownership filter.

= 0.2.1 =
* Course Builder meta box on the course editor: add modules, name them, and assign lessons via checkboxes. Persists through the modules schema and maintains the reverse lesson -> course / lesson -> module association (with clean detach when a lesson is removed). No build step, no jQuery.

= 0.2.0 =
* Phase 1 content model: sglms_course, sglms_lesson, sglms_quiz, and sglms_certificate_tpl post types; course category/tag and difficulty taxonomies (with seeded Beginner/Intermediate/Advanced terms); course/lesson/protection meta with auth and sanitize callbacks; strict modules JSON schema with validation; admin menu shell (Overview, Courses, Quizzes, Certificate Templates, Enrollments, Reports, Settings).

= 0.1.0 =
* Phase 0 scaffold: bootstrap, autoloader, custom tables via dbDelta, roles and capabilities, required pages, protected uploads directory, clean activate/deactivate/uninstall lifecycle.
