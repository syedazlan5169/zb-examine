# ZB Examine — Current State

Last updated: 2026-09-08

## Project Status

Initial Laravel and Docker development foundation is operational.

The current domain foundation includes the `Examination`, `ExaminationCustomsFormNumber`, and `ExaminationPhoto` models, related enums, and the Nombor Borang Kastam parser.

The submission-number generator is implemented: `App\Services\SubmissionNumberGenerator` allocates unique `ZB-YYMMDD-NNNN` numbers backed by a dedicated `submission_sequences` counter table with MySQL row-level locking, using the `Asia/Kuala_Lumpur` business timezone (`config('zb-examine.business_timezone')`) independently of the application's UTC `config('app.timezone')`. See D018 in docs/DECISIONS.md.

The core examination submission pathway is implemented: `App\Services\ExaminationSubmissionService` creates one complete non-photo submission by composing the parser and the number generator. See D019 in docs/DECISIONS.md.

The user-facing form, authentication and photo workflow have not yet been implemented.

The Nombor Borang Kastam parser is now implemented and covered by dedicated unit tests. It normalizes complete numbers, expands the confirmed two-digit shorthand format, rejects malformed or duplicate values, and does not perform persistence.

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

`App\Services\ExaminationSubmissionService::submit(ExaminationSubmissionData $data, ?User $user = null, ?CarbonInterface $instant = null): Examination`

Operation order is fixed: capture one immutable UTC instant, parse the customs form numbers, allocate the submission number (committing on its own), then persist the `examinations` row and all `examination_customs_form_numbers` rows in one transaction.

The same captured instant feeds both the number's business-date bucket and `submitted_at`, which is stored in UTC. Agent snapshot fields always come from the submitted data, never from the `User` record. `display_order` on child rows is 1-based.

`submit()` must not be called inside an existing database transaction; the generator's guard rejects that at runtime.

`App\Data\ExaminationSubmissionData` is a `final readonly` DTO with a private constructor and a single `fromValidated(array): self` entry point. It resolves enum backing strings to enum instances and canonicalizes `form_type_other` / `reason_other` so stale hidden-form values cannot be persisted. It intentionally carries no `submission_no`, `user_id`, `submitted_at` or photo data.

Parser, generator and database exceptions propagate unwrapped. A persistence failure rolls the examination and every child row back while the allocated submission number stays permanently consumed.

Test coverage:

```text
tests/Feature/Services/ExaminationSubmissionServiceTest.php
    guest and registered-agent submissions, snapshot independence from the
    User profile, shorthand and mixed customs-number expansion with 1-based
    display_order, parser rejection consuming no sequence number,
    parent-failure and child-failure rollback with a permanently consumed
    number, *_other canonicalization, submitted_at UTC persistence,
    UTC/KL midnight boundary, sequential submissions
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

`ExaminationSubmissionRequest::prepareForValidation()` converts an empty-string `reason` (as posted by a `<select>` placeholder) to `null` before validation, and nulls `form_type_other`/`reason_other` whenever their controlling field isn't `other`, ahead of the DTO's own canonicalization. Enum fields are validated with `Illuminate\Validation\Rule::enum(...)`; string length limits mirror the actual `examinations` table columns.

`ExaminationController::store()` builds `ExaminationSubmissionData::fromValidated()` and calls `ExaminationSubmissionService::submit()` directly — it does not parse customs form numbers or allocate submission numbers itself. `App\Exceptions\InvalidCustomsFormNumberInput` is mapped to a localized field error on `customs_form_numbers`; `App\Exceptions\SubmissionNumberSequenceExhausted` becomes a localized form-level `submission_error`; any other `Throwable` is reported via `report()` and shown only as a safe generic localized failure message. No exception internals are ever rendered.

On success, the generated `submission_no` is stored in normal (non-flash) session state (`examination_success`), so refreshing `examinations.success` keeps showing it; visiting `examinations.create` again clears that state. There is no public route containing a `submission_no`.

Bilingual copy lives in `lang/{ms,en}/examination.php` (headings, field/option labels, parser-error mappings, success/failure/loading text) and a small `ms`-only subset of `lang/ms/validation.php` (only the rule keys this form actually uses — everything else falls back to `lang/en/validation.php` per key). The existing `app.name` translation key now holds the official product name (`Sistem Daftar Pemeriksaan` / `Examine Registration System`) and is reused in the shared layout instead of introducing a competing key.

Test coverage: `tests/Feature/ExaminationSubmissionFormTest.php` (uses `DatabaseMigrations`, not `RefreshDatabase`, for the same reason as the Step 2G service tests) covers guest access, locale rendering, required/enum/conditional validation, the `reason=''` → `null` normalization, parser-error field mapping without consuming a submission number, shorthand parsing, guest vs. authenticated snapshot independence, the success/refresh/clear-on-new-submission session flow, sequence-exhaustion safe messaging, and unexpected-exception safe messaging (asserted via `Illuminate\Support\Facades\Exceptions::fake()`).

Photos are not implemented in this form. The Blade layout leaves a natural, currently-empty `<section data-future-section="photos">` placeholder in `examinations/create.blade.php` for the future Photo section, but no photo inputs, compression, or storage integration exist yet.

## Next Development Stage

The examination domain, its core submission pathway, and the guest-facing non-photo submission form are implemented and tested. Next work should build on top of the existing form.

Important upcoming areas include:

```text
Photo capture, client-side compression, and examination_photos persistence
DigitalOcean Spaces integration
Authentication and registered-agent profile auto-fill
Agent submission history
Officer search/detail interface
```

The photo workflow remains undesigned. Photos are deliberately excluded from `ExaminationSubmissionService`; how uploads are associated with an examination is a future decision, not a settled one.

Do not start implementing these blindly from assumptions.

The existing Google Form should be mapped and reviewed so the new application preserves required operational fields while improving weak parts of the previous workflow.