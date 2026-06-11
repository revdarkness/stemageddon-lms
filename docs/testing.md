# STEMageddon LMS — Manual Test Scripts

Per-phase manual verification. Run on a fresh WordPress 6.4+ install (wp-env or LocalWP).

---

## Phase 0 — Scaffold

**Goal:** clean activate / deactivate / uninstall cycle; tables, roles, and pages created on activation; no orphaned data when uninstall opt-in is enabled.

### Setup
- Fresh WordPress 6.4+ (`wp-env start` or LocalWP).
- Copy/symlink the `stemageddon-lms` folder into `wp-content/plugins/`.

### T0.1 — Activation creates schema
1. Activate **STEMageddon LMS** from Plugins.
2. Confirm no PHP errors/warnings on activation (check `wp-content/debug.log` with `WP_DEBUG` on).
3. Verify tables exist:
   ```
   wp db query "SHOW TABLES LIKE '%sglms_%'"
   ```
   Expect 6: `enrollments`, `progress`, `quiz_attempts`, `certificates`, `downloads_log`, `notes` (each with the site table prefix).
4. Verify options:
   ```
   wp option get sglms_db_version    # -> 1
   wp option get sglms_version       # -> 0.1.0
   wp option get sglms_token_secret  # -> 64-char string, non-empty
   wp option get sglms_page_dashboard / sglms_page_catalog / sglms_page_denied  # -> page IDs
   ```

### T0.2 — Roles and capabilities
1. `wp role list` shows `sglms_student` and `sglms_instructor`.
2. `wp cap list administrator | grep sglms` shows all four LMS caps.
3. `wp cap list sglms_instructor` includes `sglms_manage_own_courses`.

### T0.3 — Pages
1. Pages **My Learning**, **Course Catalog**, **Access Denied** exist and are published.
2. Re-activating the plugin does NOT create duplicate pages (idempotency).

### T0.4 — Protected uploads directory
1. `wp-content/uploads/sglms-protected/` exists.
2. It contains `index.php` (blank) and `.htaccess` (deny rule).

### T0.5 — Deactivation is non-destructive
1. Deactivate the plugin.
2. Confirm tables, options, roles, and pages STILL exist (deactivation only flushes rewrite rules).

### T0.6 — Uninstall, opt-out (default)
1. With `sglms_delete_data_on_uninstall` unset/false, delete the plugin via Plugins screen.
2. Confirm tables, options, roles, and pages are RETAINED.

### T0.7 — Uninstall, opt-in
1. Reinstall/activate.
2. `wp option update sglms_delete_data_on_uninstall 1`.
3. Delete the plugin via the Plugins screen.
4. Confirm: all 6 tables dropped, all `sglms_*` options gone, both custom roles removed, admin LMS caps stripped, the three pages deleted, and `uploads/sglms-protected/` removed.

### Pass criteria
- No fatal errors at any lifecycle step.
- T0.1–T0.5 leave data intact; T0.7 leaves zero orphaned data; T0.6 retains data.

---

## Phase 1 — Content model

**Goal:** CPTs, taxonomies, meta, the modules JSON schema, and the admin menu shell all register and behave. Builder payloads are never trusted.

Automated by `.local-test/verify-phase1.sh`. Manual equivalents:

### P1.1 — Post types
1. `wp post-type list | grep sglms` shows `sglms_course`, `sglms_lesson`, `sglms_quiz`, `sglms_cert_tpl`.
2. Note: post type names are capped at 20 chars by WP — the plan's `sglms_certificate_tpl` (21) is registered as `sglms_cert_tpl`.

### P1.2 — Taxonomies
1. `wp term list sglms_difficulty` lists Beginner / Intermediate / Advanced (seeded once, idempotent).
2. `sglms_course_cat` (hierarchical) and `sglms_course_tag` exist and show on the course list table.

### P1.3 — Modules schema (security)
1. Create a course and two lessons.
2. Save modules JSON containing a real lesson ID and a bogus ID (e.g. 999999):
   `Sglms_Modules_Schema::save( $course_id, '[{"id":"m1","title":"Intro","lessons":[<L1>,999999]}]' )`.
3. Confirm the stored structure KEEPS the real lesson and DROPS 999999 (it is not a `sglms_lesson`).
4. `Sglms_Modules_Schema::ordered_lesson_ids( $course_id )` returns the clean ordered list.

### P1.4 — Protection meta
1. `sanitize_protection('course:5')` → `course:5`; `('members')` → `members`.
2. `sanitize_protection('javascript:alert(1)')` → `none` (anything not matching the allowed forms collapses to none).

### P1.5 — Permalinks
1. With pretty permalinks on, a published course resolves at `/courses/{slug}/` with HTTP 200 (no 404).
2. The admin menu "STEMageddon LMS" shows Overview, Courses, Quizzes, Certificate Templates, Enrollments, Reports, Settings.

### Pass criteria
- All four CPTs and three taxonomies register; difficulty terms seeded exactly once.
- Modules schema discards non-lesson IDs and malformed entries.
- Protection meta only ever stores `none | members | course:{id} | level:{slug}`.
- No PHP notices in `debug.log` during activation or course/lesson creation.

---

## Phase 2 — Access control

**Goal:** a single access authority and zero content leakage across every surface. Automated by `.local-test/verify-phase2.sh`.

### P2.1–P2.2 — Authorization logic
1. `Sglms_Gatekeeper::can_access( $post_id, 0 )` is false for a members-only post, true for a public post, true for a user who can edit it.
2. `Sglms_Gatekeeper::evaluate('members',0)` false; `('none',0)` true; `('level:gold',0)` false (no membership layer yet); `can_access_course($id,0)` false until enrollment exists (Phase 3).

### P2.3 — Direct URL (the leak test)
1. As a logged-out user, request a protected post's permalink.
2. Expect a 301/302 redirect to the access-denied page with `?sglms_from={id}` — never a 200, never a 404.
3. The secret body never appears in the response (followed or not). The denied page shows the restricted message + login/enroll CTAs.
4. Note: a redirect (not in-place 403 render) is used so the plugin is portable to block themes, where `get_header()`/`get_footer()` are invalid.

### P2.4 — REST single item
1. `GET /wp-json/wp/v2/posts/{id}` for a protected post as anonymous returns `content.rendered` = restricted message, `content.protected = true`. The real body is absent.

### P2.5 — Search / collections
1. Anonymous `GET /wp-json/wp/v2/posts?search={marker}` excludes protected posts.
2. Public posts are still returned (no over-filtering).

### P2.6 — Instructor ownership
1. An `sglms_instructor` user CANNOT edit a course authored by someone else (`user_can` false) but CAN edit their own.
2. Instructor has `sglms_manage_own_courses`, lacks `sglms_manage_courses`.

### Pass criteria
- No protected content leaks via direct URL, the_content, excerpt, search, feeds, or REST.
- Denial is a redirect/CTA, never a 404 or a 200 with content.
- `debug.log` is clean on a block theme (no get_header/get_footer deprecation).
- Instructors are confined to their own LMS content.

---

## Phase 3 — Enrollment + progress

**Goal:** enrollment lifecycle, progress tracking, completion detection, and drip. Automated by `.local-test/verify-phase3.sh`.

### P3.1 — Enrollment states
1. `enroll()` → status `enrolled`; `is_enrolled()` true.
2. `revoke()` → `is_enrolled()` false; re-`enroll()` reactivates to `enrolled`.

### P3.2 — Gatekeeper integration (the Phase 2 loop)
1. A non-enrolled user `can_access()` a course lesson → false.
2. An enrolled user → true. (Enrollment answers `sglms_user_can_access_course`.)

### P3.3 — Self-enroll
1. Free course (`_sglms_price_mode = free`): `self_enroll()` succeeds.
2. Non-free course: returns a `WP_Error` (403).

### P3.4 — Progress + completion
1. Progress starts at 0%. Completing 1 of 2 required lessons → 50%, course still `enrolled`.
2. Completing all required lessons → 100% and the enrollment flips to `completed` (proves `check_course_completion` ran and `sglms_course_completed` fired).

### P3.5 — Drip
1. Sequential: lesson 1 (first) available; lesson 2 locked until lesson 1 is complete, then unlocks.
2. Schedule (`{"mode":"schedule","lessons":{"<id>":7}}`): unscheduled lesson open, the 7-day lesson locked right after enrollment.

### P3.6 — REST
1. At least three `sglms/v1` routes register (enroll, lesson complete, course progress).

### Pass criteria
- Enrollment is idempotent and reversible; access follows enrollment.
- Progress measured against required lessons; completion auto-detected and fired once.
- Drip locks/unlocks correctly for both modes; managers always see everything.

---

## Phase 4 — Lesson player front end

**Goal:** catalog, course landing, and the lesson player render correctly and stay theme-portable. Automated by `.local-test/verify-phase4.sh` (direct template render) plus a live anonymous curl of the catalog page.

### P4.1 — Catalog
1. `[sglms_catalog]` renders a grid of published courses with a search/category/difficulty filter form.
2. Live page enqueues `assets/css/frontend.css` and `assets/js/frontend.js`.

### P4.2 — Course landing
1. Anonymous visitor sees a "Log in to enroll" CTA; the syllabus accordion lists modules and lessons.
2. An enrolled student sees "Continue learning" and a progress bar.

### P4.3 — Lesson player (enrolled)
1. Curriculum sidebar lists all lessons (current highlighted, completion checks, drip locks).
2. Lesson body renders; mark-complete button, notes textarea, and prev/next are present.

### P4.4 — Drip lock
1. With sequential drip, opening a still-locked lesson shows the lock notice and hides the body.

### P4.5 — Notes
1. `Sglms_Notes::save()`/`get()` round-trips; content is `wp_kses_post`-sanitized.

### P4.6 — Comment scoping
1. `comments_open` filter returns true for an enrolled user, false for a non-enrolled user, on a lesson.

### Pass criteria
- Catalog/landing/player render via the_content (no template takeover) and work on a block theme.
- The player reflects per-user progress and drip state; notes are private per user.
- Lesson discussion is limited to enrolled users.

---

## Phase 5 — Quizzes

**Goal:** all five question types grade correctly server-side, correct answers never reach the client, attempt limits hold, and a passed required quiz completes its course.

### P5.1 — Grading per type
1. All-correct answers across mc/multi/tf/short/order score 100% and pass; all-wrong score 0%.
2. Short answer matches case-insensitively as a substring keyword.
3. Multi-select requires the exact correct set (partial selection is wrong).
4. Ordering requires the exact sequence.

### P5.2 — Answer-leak prevention
1. `Sglms_Quiz::public_questions()` contains no `correct` flags and no short-answer `answers`. (Ordering options are shuffled.)

### P5.3 — Attempt limits
1. With limit = 1, after one recorded attempt the user is blocked from another.

### P5.4 — Completion integration
1. A course with a required quiz does not complete on lesson completion alone.
2. After a passing attempt is recorded, `check_course_completion` completes the course.

### Pass criteria
- Grading is entirely server-side; the browser never receives correct answers.
- Attempt limits enforced on both start and submit.
- Required quizzes gate course completion (ties into Phase 3).

---

## Phase 6 — Certificates

**Goal:** a PDF certificate is auto-issued on completion, downloadable by the owner, and publicly verifiable; admins can manage them.

### P6.1–P6.2 — Auto-issue + PDF
1. Completing a course fires `sglms_course_completed`, which issues a certificate.
2. The generated file exists, begins with `%PDF`, and is non-trivial in size.

### P6.3 — Verification ID
1. The UID is 20 alphanumeric characters (non-sequential, `wp_generate_password`).

### P6.4–P6.5 — Lookup + regenerate
1. `Sglms_Cert_Verify::lookup()` reports valid with the correct name and course.
2. Regenerate re-renders the PDF in place, keeping the same UID/record.

### P6.6–P6.7 — Endpoints
1. The `verify-certificate` rewrite rule and the `/sglms/v1/certificates/{uid}/verify` REST route register.
2. `/verify-certificate/{uid}/` (note trailing slash; WP 301-redirects to add it) renders a standalone page showing Valid + name + course. REST returns `valid:true`.

### P6.8 — Revoke
1. After revoke, both the page and REST report invalid/revoked.

### Manual
- Build a template under LMS → Certificate Templates: pick a background, drag fields, Update. Set it (or leave none for the default layout). Complete a course and download the PDF from the dashboard.
- Note: the verification URL is printed as text on the PDF; a QR code is a later enhancement.

### Pass criteria
- PDFs render server-side via vendored FPDF, stored in the protected uploads dir, streamed only to the owner/manager.
- Verification works publicly via pretty URL and REST; revocation is reflected immediately.

---

## Phase 7 — Protected downloads

**Goal:** resources reach only enrolled users, through expiring per-user tokens; raw media URLs cannot be shared. Automated by the `_p7test.php` eval-file run.

### P7.1–P7.3 — Tokens
1. A minted token validates back to the right type/id/course/user.
2. A token with a flipped signature char, or garbage, is rejected.
3. A correctly-signed but expired token is rejected (15-minute TTL).

### P7.4–P7.5 — Whitelist + aggregation
1. `.pdf` is allowed; `.exe` is blocked by the whitelist.
2. `course_resources()` aggregates course + lesson attachments, dedupes, and drops disallowed types.

### P7.6 — URLs + routing
1. `file_url()` / `zip_url()` produce `/sglms-download/{token}` links; the rewrite rule registers.

### P7.7 — Authorization
1. The controller's gate (`can_access_course`) denies a non-enrolled user and allows an enrolled one.

### P7.8 — Logging
1. Each download inserts a log row (IP stored only as a salted hash).

### Manual
- Add files via the Resources / Downloads meta box on a course or lesson.
- As an enrolled user, open the lesson player Resources tab: individual files download via tokens, and "Download all course files" streams a zip. Copy a token URL and open it logged-out or as another user — it is refused.

### Pass criteria
- No raw media URL is exposed; all downloads flow through the tokenized controller.
- Tokens are short-lived, signed, and bound to the user; enrollment is re-checked at stream time.
- Zip-all builds on the fly; downloads are logged without storing raw IPs.

---

## Phase 8 — Reports, emails, settings, WooCommerce

**Goal:** admin reporting + CSV, transactional emails, a settings screen, and the WooCommerce bridge. Automated by the `_p8test.php` eval-file run.

### P8.1–P8.3 — Emails
1. Each email type is enabled by default and can be disabled in Settings.
2. Built subjects/bodies interpolate the course title and student name.
3. Enrollment fires student + instructor emails; completion fires the student email (captured via `pre_wp_mail`).

### P8.4 — Settings sanitization
1. The whitelist field parses mixed delimiters and lowercases extensions.
2. The settings array normalizes toggles to 0/1 and ids to ints.

### P8.5 — Reports
1. `totals()` returns all metric keys; `per_course()` includes each course with an enrollment count and completion rate. CSV export is available per table.

### P8.6 — WooCommerce
1. `Sglms_Woo_Bridge` implements `Sglms_Payment_Adapter`, ids itself `woocommerce`, and reports inactive when WooCommerce is absent. When active, a completed/processing order enrolls the buyer in mapped courses.

### Manual
- LMS → Settings: assign pages, edit the whitelist, toggle emails, pick a default certificate template, set the uninstall behavior.
- LMS → Reports: review totals/tables and export CSV.
- Course editor → Course Settings: set enrollment mode, duration, certificate template, and (with Woo) a product id.

### Pass criteria
- Emails are filterable and individually toggleable.
- Settings persist via the Settings API with sanitization.
- Reports compute from the custom tables and export valid CSV.
- The Woo bridge is inert unless WooCommerce is active.

---

## Phase 9 — Packaging + deployment

**Goal:** a clean installable artifact, self-hosted updates, a real coding-standards pass, and a smoke-test matrix.

### Coding standards
- Run PHPCS with WordPress-Extra: `composer install` (dev) then `composer lint` (or `phpcs --standard=phpcs.xml.dist`).
- Result: 0 errors. Remaining warnings are benign and intentional (unused hook-callback params required by signatures; recursive `unlink` in uninstall cleanup).

### Packaging
- `bash bin/package.sh` builds `../stemageddon-lms-{version}.zip` with a top-level `stemageddon-lms/` folder, excluding dev files (composer, phpcs config, bin, docs, assets/src, .git). The vendored `lib/` (FPDF, update checker) is included.
- Verified: every PHP file inside the built zip passes `php -l`.

### Updates
- The bundled plugin-update-checker points at the GitHub repo's releases. Each site sees updates in its normal Updates screen. Private repos: provide a token via the `sglms_github_auth_token` filter.

### Smoke matrix (run per release)
1. Fresh install + activate on stock WP 6.4+ — clean (no PHP notices).
2. Deactivate/reactivate (upgrade path) — data retained, rewrites re-flushed.
3. Classic theme (Twenty Twenty-One) and block theme (Twenty Twenty-Five) — course/lesson render via the_content; no get_header/get_footer deprecation.
4. Without WooCommerce — bridge inert. With WooCommerce — order completion enrolls (manual verification on a Woo site).
5. Uninstall: opt-out retains data; opt-in drops all tables, options, roles, pages, and the protected dir. Verified, then restored by reactivation.

### Pass criteria
- PHPCS WordPress-Extra: 0 errors.
- Zip installs and activates cleanly; same zip works on multiple installs (no hardcoded paths).
- Renders on both classic and block themes.
