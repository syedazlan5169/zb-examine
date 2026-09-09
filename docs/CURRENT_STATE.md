# ZB Examine — Current State

Last updated: 2026-09-10

## Project Status

Initial Laravel and Docker development foundation is operational.

The current domain foundation includes the `Examination`, `ExaminationCustomsFormNumber`, and `ExaminationPhoto` models, related enums, and the customs form number normalizer.

The submission-number generator is implemented: `App\Services\SubmissionNumberGenerator` allocates unique `ZB-YYMMDD-NNNN` numbers backed by a dedicated `submission_sequences` counter table with MySQL row-level locking, using the `Asia/Kuala_Lumpur` business timezone (`config('zb-examine.business_timezone')`) independently of the application's UTC `config('app.timezone')`. See D018 in docs/DECISIONS.md.

The core examination submission pathway is implemented: `App\Services\ExaminationSubmissionService` creates one complete submission by composing the customs-form-number normalizer, the number generator, and photo finalization. See D019/D022/D023 in docs/DECISIONS.md.

The real Examination form, photo integration, customs-form-number UI, and expired-session/orphan-object cleanup are implemented (Steps 3B.4 and 3B.5). Authentication/profile auto-fill has not yet been implemented.

Step 3B.6A adds the private `photo_uploads_spaces` filesystem disk and isolated
DigitalOcean Spaces primitives. The existing local `photo_uploads` disk remains
unchanged and remains the default proxy-mode disk; persisted disk names are never
silently remapped. The new primitives generate staging/sealed paths, create
staging-only presigned PUT capabilities, HEAD and conditionally GET staging
objects, validate actual JPEG bytes through temporary files, and upload the
retained verified snapshot through a server-authenticated PUT to a fresh sealed
candidate. The live
photo-upload routes, direct staging authorization, sealed-candidate ownership
claim, browser direct-upload flow, and the staging finalization guard are now
integrated by Step 3B.6B. The proxy workflow remains unchanged.

The direct browser flow uses a staging-only XMLHttpRequest with the exact signed
method and headers, `withCredentials = false`, and no application credentials.
An uncertain PUT outcome is completed first. A verified completion ends the
attempt; only stable `upload_not_ready` or `source_changed` errors allow one
fresh authorization and one retry of the current optimized JPEG. Explicit user
cancellation never enters this recovery path. Step 3B.6C remains pending.

Real provider proof against `space-probono-apps` in SGP1 established presigned
staging PUT, overwrite behavior, HEAD, conditional GET with `If-Match`, and
DeleteObject, including logical success for an already-absent object. The
configured endpoint is `https://sgp1.digitaloceanspaces.com`, with `us-east-1`
used as the AWS SDK signing region. It also established that Spaces did not
enforce stale `CopySourceIfMatch`: a stale conditional CopyObject copied the
newer staging object. Conditional CopyObject is therefore prohibited for
evidence sealing.

The corrected real-provider probe proved frozen snapshot A -> staging B -> sealed
A. Snapshot A SHA-256 was
`6b722cb3db04ab54eee236014d2e7079975260b148197ee4611a9ca7786719ac`, staging B
SHA-256 was
`42d2d4c864948a5ab8f474ce762d285f159335c04ac1308c0e1e668dc687c159`, and the
sealed object matched A rather than B. An unauthenticated HTTPS GET to the sealed
object returned HTTP 403, proving the sealed object remained private. Credentials
remain only in local environment configuration and were not committed.

Step 3B.5 provides `photo-uploads:cleanup`, an hourly scheduled command with dry-run and batch-limit options. It stages expired unfinalized sessions into the durable cleanup queue, defers physical deletion through the configured photo transport, protects finalized evidence, and integrates explicit photo removal with the same queue. Historical storage orphans from before this queue existed are intentionally outside the scope of this implementation because the application has no authoritative filesystem inventory.

`App\Services\CustomsFormNumberNormalizer` (D023) replaces the retired `CustomsFormNumberParser`. It accepts free-form, opaque customs form numbers (no `B`+11-digit syntax, no shorthand expansion), trims each value, rejects empty/oversized/duplicate (case-insensitive) values, and preserves input order — covered by dedicated unit tests.

## Submission Number Generation

`App\Services\SubmissionNumberGenerator::generate(?CarbonInterface $instant = null): string` allocates a `ZB-YYMMDD-NNNN` number for the business date of the given instant (or now), using the `submission_sequences` table and `SELECT ... FOR UPDATE` inside a transaction. Exhaustion beyond `9999` for a business date throws `App\Exceptions\SubmissionNumberSequenceExhausted`.

`generate()` refuses to run if the connection already has an active transaction (`DB::connection()->transactionLevel() > 0`), throwing `App\Exceptions\SubmissionNumberAllocationInsideTransaction`. This makes the permanent-gap invariant enforced rather than conventional: allocation always commits on its own before a caller can wrap it in a larger transaction.

Test coverage:

```text
tests/Feature/Services/SubmissionNumberGeneratorTest.php
    first number, sequential numbers, next-date reset, zero-padding,
    YYMMDD formatting, UTC/KL timezone boundary, exhaustion,
    rejection when called inside an active transaction,
    examinations.submission_no DB uniqueness

tests/Concurrency/SubmissionNumberGeneratorConcurrencyTest.php
    real MySQL 8.4, pcntl_fork with 20 competing processes,
    run only via: vendor/bin/phpunit -c phpunit.concurrency.xml
    against the isolated `zb_examine_test` schema (never the dev database)
```

### Test database provisioning (`zb_examine_test`)

`zb_examine_test` is disposable test infrastructure for the concurrency suite only. The concurrency suite must never run against `zb_examine` (the dev database); both the test itself and the provisioning command below refuse to run unless the resolved database is exactly `zb_examine_test`.

One-time step on a fresh environment (creates the schema and grants the app user access to it — requires MySQL root):

```bash
docker compose exec db mysql \
  -uroot \
  -prootsecret \
  -e "CREATE DATABASE IF NOT EXISTS zb_examine_test; GRANT ALL PRIVILEGES ON zb_examine_test.* TO 'zb_examine'@'%'; FLUSH PRIVILEGES;"
```

Repeatable migration/reset of that schema, run any time afterwards:

```bash
docker compose exec app php artisan zb-examine:provision-concurrency-testing-database
```

(run with `DB_DATABASE=zb_examine_test` etc. in the environment; the command refuses to run against any other database.)

Note: any suite using `DatabaseMigrations` rolls its migrations back after the final test, leaving the schema empty. Re-run the provisioning command before the concurrency suite if a MySQL parity run preceded it.

## Examination Submission

`App\Services\ExaminationSubmissionService::submit(ExaminationSubmissionData $data, PhotoUploadSessionCredentials $photoCredentials, ?User $user = null, ?CarbonInterface $instant = null): Examination`

Operation order is fixed: capture one immutable UTC instant, normalize/validate the customs form numbers (D023), run the unlocked photo-session precheck (D022), allocate the submission number (committing on its own), then persist the `examinations` row, `examination_customs_form_numbers` rows, `examination_photos` rows, and the photo-session claim in one transaction.

The same captured instant feeds both the number's business-date bucket and `submitted_at`, which is stored in UTC. Agent snapshot fields always come from the submitted data, never from the `User` record. `display_order` on child rows is 1-based.

`submit()` must not be called inside an existing database transaction; the generator's guard rejects that at runtime.

`App\Data\ExaminationSubmissionData` is a `final readonly` DTO with a private constructor and a single `fromValidated(array): self` entry point. It resolves enum backing strings to enum instances and canonicalizes `form_type_other` / `reason_other` so stale hidden-form values cannot be persisted. It carries `customsFormNumbers` as a plain ordered array of strings (not a raw comma-separated string). It intentionally carries no `submission_no`, `user_id`, `submitted_at`, or raw photo credentials (see `App\Data\PhotoUploadSessionCredentials`, D022).

Normalizer, generator and database exceptions propagate unwrapped. A persistence failure rolls the examination and every child row back while the allocated submission number stays permanently consumed.

Test coverage:

```text
tests/Feature/Services/ExaminationSubmissionServiceTest.php
    guest and registered-agent submissions, snapshot independence from the
    User profile, multiple free-form customs numbers with 1-based
    display_order, duplicate/empty-collection rejection consuming no
    sequence number, parent-failure and child-failure rollback with a
    permanently consumed number, *_other canonicalization, submitted_at UTC
    persistence, UTC/KL midnight boundary, sequential submissions
```

Those rollback tests use test-scoped `Event::listen('eloquent.creating: ...')` listeners; no production hooks or "simulate failure" arguments exist.

This suite is also verified against real MySQL 8.4 via the disposable `zb_examine_test` schema:

```bash
docker compose exec \
  -e DB_CONNECTION=mysql -e DB_HOST=db -e DB_PORT=3306 \
  -e DB_DATABASE=zb_examine_test -e DB_USERNAME=zb_examine -e DB_PASSWORD=secret \
  app php artisan test --filter=ExaminationSubmissionServiceTest
```

## Runtime

Current application versions observed during setup:

```text
PHP              8.4.25
Laravel          13.30.1
Composer         2.10.3
Node             24.20.0
npm              11.19.0
Vite             8.2.2
MySQL            8.4
Redis            8
```

## Docker Services

Development Compose stack currently contains:

```text
app
nginx
db
redis
node
```

### app

Custom PHP 8.4 FPM image.

Important installed PHP capabilities include:

```text
bcmath
exif
gd
intl
mbstring
opcache
pcntl
pdo_mysql
redis
zip
```

GD is compiled with JPEG, FreeType, and WebP support in preparation for future image work.

Composer is copied into the application image.

### nginx

Nginx serves Laravel through PHP-FPM.

Development address:

```text
http://localhost:8080
```

Nginx sends PHP requests to:

```text
app:9000
```

The PHP-FPM port is internal to the Compose network and is not published to the Mac host.

### db

MySQL 8.4.

Development database:

```text
DB_HOST=db
DB_PORT=3306
DB_DATABASE=zb_examine
DB_USERNAME=zb_examine
```

Database data is stored in the `mysql_data` Docker named volume.

Laravel default migrations have successfully run.

Existing tables currently include:

```text
cache
cache_locks
failed_jobs
job_batches
jobs
migrations
password_reset_tokens
sessions
users
```

### redis

Redis 8 Alpine.

Redis has been tested both directly and through Laravel.

Observed successful tests:

```text
redis-cli ping
=> PONG
```

and Laravel cache successfully wrote/read a Redis-backed test value.

Current intended Laravel responsibilities:

```text
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=database
```

Redis data is stored in the `redis_data` Docker named volume.

### node

Node 24 Alpine.

Vite runs with:

```text
npm run dev -- --host 0.0.0.0
```

Development Vite port:

```text
5173
```

`node_modules` is stored in a Docker named volume.

Laravel frontend styling successfully loads through Vite.

## Docker Networking

Compose service names are used as DNS hostnames.

Examples:

```text
nginx -> app:9000
Laravel -> db:3306
Laravel -> redis:6379
```

Do not hard-code Docker container IP addresses.

During development it was observed that recreating the `app` container while leaving Nginx running could leave Nginx temporarily targeting the previous resolved PHP container address.

Restarting Nginx corrected the resulting 502 response:

```bash
docker compose restart nginx
```

## Localization

Localization foundation is operational.

Configured frontend locales:

```text
ms
en
```

Default:

```text
ms
```

Fallback:

```text
en
```

Locale selection is stored in the session.

A `SetLocale` middleware applies the session/default locale to web requests.

A temporary `/locale-test` page successfully switches between Bahasa Melayu and English.

Official frontend names:

```text
ms:
Sistem Daftar Pemeriksaan

en:
Examine Registration System
```

## Current Architecture

```text
                         Browser
                            |
               +------------+------------+
               |                         |
               v                         v
        localhost:8080            localhost:5173
               |                         |
               v                         v
            Nginx                     Vite
               |
               | FastCGI
               v
         PHP 8.4 FPM
          Laravel 13
           /       \
          /         \
         v           v
      MySQL         Redis
       8.4            8
        |              |
        v              v
   mysql_data      redis_data
```

## Examination Submission Form

A public, mobile-first Blade submission form exists at `/` (route `examinations.create`). Implementation is plain Blade + `App\Http\Requests\ExaminationSubmissionRequest` + `App\Http\Controllers\ExaminationController` + a small vanilla JavaScript module (`resources/js/examination-form.js`) — no Livewire, no Alpine, no new dependencies. Livewire remains uninstalled; it was deliberately not introduced for this step.

Routes:

```text
GET  /                    examinations.create
POST /examinations        examinations.store
GET  /examinations/success examinations.success
```

The create/store routes are guest-accessible; no authentication is required to submit. If a user happens to be authenticated, `auth()->user()` is passed to `ExaminationSubmissionService::submit()`, but no login/registration screens exist yet and no profile auto-fill is implemented.

`ExaminationSubmissionRequest::prepareForValidation()` converts an empty-string `reason` (as posted by a `<select>` placeholder) to `null` before validation, nulls `form_type_other`/`reason_other` whenever their controlling field isn't `other`, and trims each `customs_form_numbers.*` array value (without deleting empty/duplicate entries, which must still fail validation) ahead of the DTO's own canonicalization. `customs_form_numbers` is validated as `required|array|min:1|max:255` with each `customs_form_numbers.*` as `required|string|max:100|distinct:ignore_case` (D023) — no DB `exists:` rule for the photo-session fields, which use the same basic-shape-only approach. Enum fields are validated with `Illuminate\Validation\Rule::enum(...)`; string length limits mirror the actual `examinations`/`examination_customs_form_numbers` table columns.

`ExaminationController::store()` builds `ExaminationSubmissionData::fromValidated()` and `PhotoUploadSessionCredentials::fromRequest()`, then calls `ExaminationSubmissionService::submit()` directly — it does not normalize customs form numbers, run the photo precheck, or allocate submission numbers itself. `App\Exceptions\InvalidCustomsFormNumberInput` is mapped to a localized field error on `customs_form_numbers`; `App\Exceptions\SubmissionNumberSequenceExhausted` and `PhotoUploadSessionInvalid`/`PhotoUploadInvalid` become a localized form-level `submission_error`; any other `Throwable` is reported via `report()` and shown only as a safe generic localized failure message. Every manual redirect goes through a `redirectBackWithInput()` helper that excludes `photo_upload_token` from flashed input (`bootstrap/app.php`'s `dontFlash()` separately covers only the automatic `ValidationException` path). No exception internals are ever rendered.

On success, the generated `submission_no` is stored in normal (non-flash) session state (`examination_success`), so refreshing `examinations.success` keeps showing it; visiting `examinations.create` again clears that state. There is no public route containing a `submission_no`.

Bilingual copy lives in `lang/{ms,en}/examination.php` (headings, field/option labels, customs-number normalizer-error mappings, repeatable-field Add/Remove/duplicate strings, success/failure/loading text), `lang/{ms,en}/examination_photos.php` (real-form photo widget copy), and a small `ms`-only subset of `lang/ms/validation.php` (only the rule keys this form actually uses, now including `array`/`distinct`/`min.array` — everything else falls back to `lang/en/validation.php` per key). The existing `app.name` translation key now holds the official product name (`Sistem Daftar Pemeriksaan` / `Examine Registration System`) and is reused in the shared layout instead of introducing a competing key. The locale switcher displays compact `MY`/`EN` labels (`lang/{ms,en}/app.php`); internal locale codes/URLs are unchanged. "Lampiran A (Tarik Balik)" renders identically in both locales (D023) — an intentionally untranslated domain term.

Test coverage: `tests/Feature/ExaminationSubmissionFormTest.php` (uses `DatabaseMigrations`, not `RefreshDatabase`, for the same reason as the Step 2G service tests) covers guest access, locale rendering, required/enum/conditional validation, the `reason=''` → `null` normalization, free-form customs-number acceptance (including that legacy comma shorthand is no longer expanded), duplicate/empty-row rejection, old-input reconstruction of multiple customs-number rows, the Lampiran A wording and compact locale switcher, guest vs. authenticated snapshot independence, the success/refresh/clear-on-new-submission session flow, sequence-exhaustion safe messaging, and unexpected-exception safe messaging (asserted via `Illuminate\Support\Facades\Exceptions::fake()`).

Photos are integrated into this form as of Step 3B.4 (see below) — the previously-empty `<section data-future-section="photos">` placeholder in `examinations/create.blade.php` has been replaced with the real photo widget, hidden session-credential fields, and atomic finalization on submit. Customs form numbers were further refined to a free-form, repeatable-field contract (D023) after Step 3B.4's initial real-device pass.

## Photo Upload Domain Foundation (Step 3B.1)

The overall photo-upload architecture (Step 3B) is approved and locked; this step implements only its persistence/domain foundation. Two new tables/models exist: `photo_upload_sessions` (`App\Models\PhotoUploadSession`) and `photo_uploads` (`App\Models\PhotoUpload`), entirely separate from the existing, unmodified `examination_photos` table. See D020 in docs/DECISIONS.md for the full rationale.

`PhotoUploadSession::issue(): array{session: PhotoUploadSession, token: string}` is the sole canonical way a session is created: it generates a high-entropy raw bearer token, persists only its SHA-256 hash (`token_hash`), and fixes `expires_at` to 24 hours from creation. The raw token is never persisted. Both models auto-generate a non-secret `public_id` (ULID) on creation via a `creating` event — primary keys remain plain bigint auto-increment, unchanged from every other table in this project. `photo_upload_sessions.examination_id` (nullable, unique, `cascadeOnDelete`) is the sole finalization signal; `photo_uploads.verified_at` (nullable) is the sole per-upload readiness signal. There is no `PhotoUploadStatus` enum and no `status`/`finalized_at`/`removed_at` column anywhere. Both models declare `$fillable = []` — nothing is mass-assignable; all writes happen through direct property assignment in controlled domain code (or, in tests, through factories, which Laravel exempts from mass-assignment guarding).

**Not yet implemented:** upload HTTP endpoints, any Storage/DigitalOcean Spaces transport, presigned URLs, HEAD verification, client-side Canvas compression, the photo UI, an abandoned-upload cleanup command, and any change to `ExaminationSubmissionService`/`ExaminationSubmissionData` to associate photos with an Examination. The session-row `lockForUpdate()` mutex and the max-10-photos invariant are documented conventions only in this step — no code enforces them yet, because no real caller exists until Step 3B.2.

Test coverage: `tests/Feature/Models/PhotoUploadSessionTest.php` and `tests/Feature/Models/PhotoUploadTest.php` (both `DatabaseMigrations`) cover identity/uniqueness, the token/hash relationship, relationships and cascade deletes (including a finalized `Examination`'s deletion cascading its claimed session), nullable-until-verified metadata, the fixed 24h expiry, abandoned-vs-finalized cleanup-query scoping, and closed mass assignment.

## Photo Upload Session HTTP API + Local Storage Transport (Step 3B.2)

Six JSON endpoints under the normal `web` middleware group (CSRF included, not exempted) now implement the full pre-Examination photo lifecycle against a private local disk. See D021 in docs/DECISIONS.md for the full rationale.

```text
POST   /photo-upload-sessions                                          create session
GET    /photo-upload-sessions/{sessionPublicId}                        resume/read state
POST   /photo-upload-sessions/{sessionPublicId}/photos                 allocate a pending photo
POST   /photo-upload-sessions/{sessionPublicId}/photos/{photoPublicId}/upload      receive JPEG bytes
POST   /photo-upload-sessions/{sessionPublicId}/photos/{photoPublicId}/complete    verify + set verified_at
DELETE /photo-upload-sessions/{sessionPublicId}/photos/{photoPublicId}             remove
```

`App\Services\PhotoUploadSessionResolver` is the single canonical token-authentication path (`resolve()` for unlocked reads, `resolveLocked()` — mandatory inside `DB::transaction()` — for every mutation). A wrong `X-Photo-Upload-Token` and a nonexistent `public_id` are indistinguishable (`invalid_session`, 401). `App\Services\PhotoUploadService` composes the resolver with `App\Services\PhotoUploadTransport` (interface) / `App\Services\LocalPhotoUploadTransport` (the private `photo_uploads` disk, `config('zb-examine.photo_upload_disk')`) for allocate/upload/complete/remove. Metadata (`mime_type`/`file_size`/`width`/`height`) is always re-derived from the actual stored object via `exif_imagetype()`/`getimagesize()`/`Storage::size()` — never trusted from the client. `complete` is idempotent (a re-call on an already-verified photo returns its unchanged state). Explicit removal stages a durable deletion intent transactionally and defers physical deletion until the cleanup queue's one-hour settling window. A stable `{"message": "...", "code": "..."}` JSON error contract covers nine machine-readable codes across `App\Exceptions\PhotoUploadSessionInvalid`/`App\Exceptions\PhotoUploadInvalid`, rendered centrally in `bootstrap/app.php`. Bilingual error copy lives in `lang/{ms,en}/photo_upload.php`.

**Not yet implemented:** client-side Canvas compression, the mobile photo-selection/preview/progress UI, and DigitalOcean Spaces/presigned PUT. Examination finalization and Step 3B.5 durable cleanup are implemented; finalized-photo pruning remains intentionally out of scope.

Test coverage: `tests/Feature/PhotoUploadSessionApiTest.php` and `tests/Feature/PhotoUploadApiTest.php` (both `DatabaseMigrations`, `Storage::fake('photo_uploads')`) cover session issuance/authentication/anti-enumeration, allocation and the 10-photo cap, upload/complete verification (including a genuinely corrupt-content JPEG rejected and cleaned up), idempotent duplicate completion, cross-session isolation, finalized/expired rejection on every mutating endpoint, row-before-object removal ordering, storage-delete-failure resilience, safe resume output, and a regression assertion that no `Examination`/`ExaminationPhoto` row is created by this phase.

### Photo preview lifecycle

- While the page remains open, newly selected/taken photos display a local preview generated from the optimized browser Blob.
- Photo upload state is recoverable after refresh through the photo-upload session.
- Preview thumbnails are **not** recoverable after a page refresh or locale change because Blob URLs are browser-memory-only and are intentionally not persisted.
- Step 3B.3 does not store image Blobs/File objects in sessionStorage, localStorage, or IndexedDB.
- After refresh, verified photo cards may therefore be restored without thumbnails.
- Restoring private previews after reload is deferred to a later integration/storage-retrieval step using authorized private image access (e.g. signed/private URLs), rather than persisting evidence image data in browser storage.

## Real Examination Form Photo Integration + Atomic Finalization (Step 3B.4)

The real, guest-facing `examinations.create` form now mounts the Step 3B.3 photo widget and
atomically finalizes 1-10 verified photos alongside every Examination submission. See D022 in
docs/DECISIONS.md for the full rationale.

`App\Services\PhotoUploadSessionFinalizer` (new) is the only place finalization happens, with
three responsibilities: `precheck()` (unlocked, fast, optimization-only), `lock()` (must run
inside an active transaction; locks the parent `photo_upload_sessions` row via the existing
`PhotoUploadSessionResolver::resolveLocked()`, then re-validates against a **fresh** post-lock
`photo_uploads` query — never precheck's loaded rows), and `attachPhotos()` (metadata-only
`examination_photos` rows with contiguous 1-based `display_order`, plus the
`photo_upload_sessions.examination_id` claim). `ExaminationSubmissionService::submit()` now takes
a second parameter, `App\Data\PhotoUploadSessionCredentials` (`publicId`, `token`) — a small
readonly DTO kept entirely separate from `ExaminationSubmissionData` so the raw bearer token is
never mass-assigned/persisted. The service's sequence is: capture immutable instant →
normalize/validate customs form numbers → unlocked photo-session precheck → allocate submission
number (unchanged, own commit) →
`DB::transaction` (lock session → create Examination → create customs rows → attach photos →
commit). All existing D018/D019 guarantees (one captured instant,
normalization-before-photo-precheck-and-allocation,
independent number-allocation commit, permanent gap on post-allocation failure, atomic
Examination persistence) remain intact; the permanent-gap policy now also explicitly covers
photo-finalization failures.

Two new `App\Exceptions\PhotoUploadInvalid` codes (`photo_count_invalid`,
`unverified_photo_pending`) cover finalize-time-only checks; existing `PhotoUploadSessionInvalid`
codes (`invalid_session`/`session_expired`/`session_finalized`) are reused unchanged.
`ExaminationSubmissionRequest` adds two basic-shape-only fields (`photo_upload_session_public_id`,
`photo_upload_token` — never a DB `exists:` rule). `ExaminationController` builds
`PhotoUploadSessionCredentials::fromRequest()` and routes every manual error redirect through a
new `redirectBackWithInput()` helper that excludes `photo_upload_token` from flashed input
(`bootstrap/app.php`'s `$exceptions->dontFlash(['photo_upload_token'])` separately covers only
the automatic `ValidationException` redirect path — both are required, neither alone suffices).

Client-side, `resources/js/examination-photos.js` mounts the existing, unmodified
`PhotoUploadManager` on the real form (no Slow Test Mode, no dev diagnostics — a
`showDiagnostics: false` config flag trims the Original/Optimized/Stage breakdown for real
users), gates the form's `submit` event on `isReadyForSubmission()`, populates the two hidden
fields fresh from the live manager session at submit time (never via Blade `old()` for the
token), and force-reloads on a BFCache-restored `pageshow`. `PhotoUploadManager
.resumeSessionIfAvailable()` was extended to detect a `finalized` resume response and clear
stale credentials rather than resurrecting them. `sessionStorage` is cleared only on the success
page (guarded on a success-page-only DOM marker, since `app.js` loads on every page), never
before/at submit — an ordinary non-photo validation failure therefore never destroys or
finalizes the still-live photo session.

No schema migration was needed: `photo_upload_sessions.examination_id` (D020) and
`examination_photos` (pre-existing) already carried every required column. `PhotoUpload`
rows/objects are intentionally left in place after finalization — pruning them remains
Step 3B.5 scope.

Test coverage: `tests/Feature/ExaminationPhotoFinalizationTest.php` and
`tests/Feature/Services/PhotoUploadSessionFinalizerTest.php` (both `DatabaseMigrations`) cover
the happy paths (guest/authenticated/10-photo), precheck rejecting 0/pending photos before number
allocation, invalid/expired/finalized session rejection, the precheck-race scenario, final-
transaction rollback (Examination + customs + examination_photos + session claim together) with
the submission number staying consumed, customs-number-validation-failure-before-photo-interaction,
1-based
contiguous `display_order` even from gapped source rows, exact metadata copying, a mocked-transport
assertion that no storage call ever occurs during finalization, ordinary-validation-failure photo
session survival, the two raw-token no-flash regressions (automatic `ValidationException` path
and manual controller-redirect path, independently), and that the real public pages never expose
private storage paths. `tests/Concurrency/DuplicatePhotoSessionSubmitTest.php` (real MySQL +
`pcntl_fork`, `phpunit.concurrency.xml`) proves at most one Examination is ever created from
concurrent submissions of the same photo session.

## Next Development Stage

The examination domain, its core submission pathway, and the full guest-facing photo-integrated submission form are implemented and tested. Next work should build on top of the existing form.

Important upcoming areas include:

```text
DigitalOcean Spaces integration
Authenticated private photo preview retrieval (signed/private URLs)
Authentication and registered-agent profile auto-fill
Agent submission history
Officer search/detail interface
```

The overall photo-upload architecture is approved and now fully integrated end-to-end (see D020/D021/D022/D024 in docs/DECISIONS.md). Photo capture, upload, atomic Examination finalization, and durable Step 3B.5 cleanup all exist and are tested. Remaining photo-adjacent work is limited to DigitalOcean Spaces, authorized private preview retrieval, and the separate future policy for pruning finalized upload metadata.

Do not start implementing these blindly from assumptions.

The existing Google Form should be mapped and reviewed so the new application preserves required operational fields while improving weak parts of the previous workflow.