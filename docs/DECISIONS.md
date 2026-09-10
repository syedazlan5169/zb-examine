## D025 - Private Spaces Staging and Sealed Evidence Architecture

Step 3B.6A provides the DigitalOcean Spaces storage primitives only. Direct
browser PUT to a final/evidence key is rejected because Spaces PUT is
overwrite-capable and does not document the create-only precondition required by
the evidence invariant.

The approved direct architecture is:

```text
browser PUT to disposable staging object
    -> HEAD staging
    -> GET staging with If-Match from HEAD
    -> authoritative JPEG validation
    -> server-authenticated PUT of the verified temporary snapshot
    -> fresh server-only sealed candidate
    -> later database ownership claim (Step 3B.6B)
    -> Examination metadata references sealed path
```

Staging keys use `photo-upload-staging/{session_public_id}/{photo_public_id}.jpg`.
Sealed candidates use `photo-uploads/{session_public_id}/{photo_public_id}/{seal_id}.jpg`.
The seal identifier is freshly generated with cryptographically strong randomness
for every attempt. The browser receives authorization only for staging and never
receives the sealed path or a sealed-key upload capability.

The source ETag is an opaque conditional identity, not an MD5/content hash. The
provider proof primitive HEADs the source, performs a conditional GET with the
HEAD identity, and validates actual JPEG bytes through a bounded temporary file.
The temporary file is retained as an owned verified payload until the caller
finishes the server-authenticated PUT to the fresh sealed candidate.

Real SGP1 provider testing found that Spaces accepted CopyObject with a stale
`x-amz-copy-source-if-match` value and copied the newer source object. Therefore
conditional CopyObject is not an evidence-integrity guard and is retired from
the application design. The sealed candidate is now written from the exact
validated local snapshot, never reread from staging.

The complete provider-proven architecture is:

```text
browser presigned PUT
    -> disposable staging object
    -> HEAD
    -> GET If-Match
    -> authoritative byte/JPEG validation
    -> retained frozen temporary snapshot
    -> fresh high-entropy sealed candidate
    -> durable candidate deletion intent
    -> server-authenticated PUT of the exact verified snapshot
    -> transactional ownership claim (Step 3B.6B)
    -> Examination evidence
```

The browser can write only staging. Staging is disposable and may be overwritten.
Sealed paths are never browser-presigned. `GET If-Match` is the snapshot
acquisition guard; the ETag is only an opaque conditional identity, never a
content hash. Once the verified temporary snapshot exists, later staging changes
are irrelevant. Every sealing attempt uses a fresh high-entropy destination, and
an uncertain sealed PUT destination is never reused.

The sealed-candidate deletion intent is intentionally created after successful
staging validation but before the server PUT. This avoids queue noise for invalid
uploads while preserving crash safety if the server PUT succeeds before the database
claim. Candidate ownership integration, staging-path ownership transfer,
production authorization routing, direct client flow, and database claims remain
deferred to Step 3B.6B.

## D026 - Direct Upload Ownership Handoff

Step 3B.6B integrates direct uploads without changing the proxy workflow. The
browser receives a presigned PUT only for the persisted staging path. Completion
verifies the staging object, creates a durable deletion intent for a fresh sealed
candidate before the server-authenticated PUT, then claims that candidate under
the session-row lock. The claim refuses any candidate whose cleanup worker has
set `deletion_started_at`; that timestamp is the irreversible cleanup ownership
boundary. A failed sealed PUT leaves its candidate intent available for retry or
deferred deletion.

Finalization rejects verified direct rows that still point at staging. Upload mode
is derived from the persisted disk and path, never from current runtime mode
configuration, so old proxy rows cannot be reinterpreted as Spaces objects.

When the browser receives an uncertain direct PUT outcome, the manager calls the
application completion endpoint before requesting another staging authorization.
If completion succeeds, the existing provider write is accepted. Only
`upload_not_ready` or `source_changed` permits one fresh authorization and one
retry of the same optimized JPEG; session, photo, and other authoritative state
errors stop the attempt. Explicit user cancellation remains an abort/remove path
and never triggers completion merely because the transport abort is technically
uncertain.

`photo_uploads` remains the existing local logical disk. The new
`photo_uploads_spaces` disk is separate so persisted local rows in
`photo_uploads`, `examination_photos`, and the cleanup queue are never silently
reinterpreted as Spaces objects. The current proxy workflow and Examination
finalization are unchanged and finalization remains storage-free.

Real DigitalOcean verification was completed against Space `space-probono-apps`
in physical region SGP1 at `https://sgp1.digitaloceanspaces.com`. The application
uses `us-east-1` as the AWS SDK signing region, as required by this Spaces
configuration. Presigned staging PUT, staging overwrite, HEAD, conditional GET,
snapshot identity, and DeleteObject passed. Deleting already-absent objects also
returned logical success.

The failed CopyObject experiment uploaded A (692 bytes), overwrote staging with B
(695 bytes), then used stale A in `CopySourceIfMatch`. Spaces accepted the copy,
and the destination contained B (695 bytes). Conditional CopyObject is therefore
prohibited for evidence sealing.

The corrected frozen-snapshot probe then passed. Snapshot A SHA-256 was
`6b722cb3db04ab54eee236014d2e7079975260b148197ee4611a9ca7786719ac`, staging B
SHA-256 was
`42d2d4c864948a5ab8f474ce762d285f159335c04ac1308c0e1e668dc687c159`, and the
sealed object SHA-256 equaled A and did not equal B. An unauthenticated HTTPS GET
to the sealed object returned HTTP 403, confirming that sealed objects remain
private. Credentials remain only in local environment configuration and are not
documented or committed.

Examination finalization remains DB-only and performs zero storage calls. Step
3B.6B, including candidate-intent integration and transactional ownership claim,
is implemented. Core desktop real-browser and provider QA then found one missing
staging-intent transition in the implementation: the sealed ownership claim
removed the winner candidate intent but initially left the old staging object
without a durable cleanup owner. Commit `28bbccd` (`fix: preserve staging
cleanup ownership on direct completion`) corrected the implementation to match
the ownership invariant already defined here. The fix stages the old staging
intent inside the same transaction as the sealed ownership update, authoritative
metadata/`verified_at` persistence, and winner candidate-intent removal; storage
operations remain outside that transaction. Rollback removes the uncommitted
staging intent while preserving the pending staging-owned row and durable
candidate intent.

Core desktop E2E QA passed. Extended/mobile resilience QA remains deferred;
automated/provider proofs remain valid. See the consolidated Step 3B.6C QA
checkpoint in `docs/CURRENT_STATE.md` for the evidence and deferred manual
coverage.

## D024 - Durable Photo Upload Cleanup

Expired, unfinalized photo upload sessions are cleaned through a durable deletion-intent queue. Cleanup stages one intent per storage object and removes the session and its child rows in the same database transaction. Explicit photo removal uses the same queue, so a storage failure cannot lose the record of an object that must be deleted.

Physical deletion is delayed by `photo_cleanup_settle_seconds` (one hour by default). This settling window protects uploads that were already in flight when database ownership was removed. Queue rows are processed oldest-first and remain available for retry when storage deletion fails. Duplicate intents are prevented by a unique `(storage_disk, storage_path)` constraint and never shorten an existing settling deadline.

Before every physical deletion, cleanup checks `examination_photos` for finalized evidence referencing the same disk and path. An integrity conflict blocks deletion and leaves the intent unresolved for investigation. Storage calls are performed after database transactions and locks have ended. The scheduler runs the command hourly with `withoutOverlapping()` as an operational optimization; correctness still depends on database uniqueness and row locking, not scheduler exclusivity.

# ZB Examine — Architecture Decisions

This document records important decisions that should not be casually changed by future development sessions or AI agents without understanding the reason behind them.

## D001 — Laravel Backend

Use PHP with Laravel.

Current development baseline:

```text
PHP 8.4
Laravel 13
```

Backend implementation remains English-first regardless of frontend locale.

## D002 — Docker-Based Development and Production

Docker is a core part of the application architecture.

Development currently uses Docker Compose.

Application services are isolated rather than installing the complete runtime directly on the host machine.

## D003 — Nginx + PHP-FPM

Do not use `php artisan serve` as the normal application webserver.

Request flow:

```text
Browser
    |
    v
Nginx
    |
    | FastCGI
    v
PHP-FPM
    |
    v
Laravel
```

In production, the intended architecture is:

```text
Internet
    |
    v
Host Nginx
TLS + domain routing
    |
    v
Docker Nginx
    |
    v
PHP-FPM / Laravel
```

This architecture will be tested on a new VPS when production deployment begins.

## D004 — MySQL

Use MySQL 8.4.

MySQL application data is persisted in a Docker named volume during development.

Database services should not be exposed to the host unless there is a specific development requirement.

## D005 — Redis

Use Redis 8 with the native PhpRedis PHP extension.

Initial responsibilities:

```text
Redis
├── application cache
└── queues
```

Sessions currently remain in MySQL.

Redis uses persistence because queued jobs should not be treated as completely disposable.

## D006 — Node and Vite

Use Node 24 LTS in Docker.

Vite runs as a dedicated development service.

`node_modules` is stored in a Docker named volume rather than being managed by the Mac host environment.

## D007 — Frontend Technology

The intended frontend stack is:

```text
Blade
Livewire
Alpine.js
Tailwind CSS
Vite
```

Do not introduce React/Vue or convert the system into a SPA without a demonstrated requirement.

The agent UI is mobile-first.

The releasing-officer UI is desktop-first but must remain responsive.

## D008 — Localization

Frontend languages:

```text
ms = Bahasa Melayu
en = English
```

Default:

```text
ms
```

Fallback:

```text
en
```

Backend code and database naming remain English.

Translation keys also use English names.

Do not hard-code user-facing Malay or English strings throughout Blade/Livewire components when translation keys should be used instead.

Official system names:

```text
Malay:
Sistem Daftar Pemeriksaan

English:
Examine Registration System
```

Do not rename it to Container Examination System / Sistem Pemeriksaan Kontena.

## D009 — Guest Submission

Authentication is optional for examination submission.

`examinations.user_id` must therefore support `NULL`.

Guest users can submit and receive a submission number.

Authenticated agents receive additional features such as submission history and profile-based auto-population.

## D010 — Historical User Snapshot

An examination should not depend solely on current information in the `users` table.

Relevant agent information should be copied/snapshotted into the examination at submission time.

This prevents historical records from changing when a user later updates their profile or company information.

## D011 — Multiple Customs Form Numbers

One examination has many customs-form / SMK numbers.

Do not store multiple SMK numbers as a single comma-separated database field.

Each complete number must be stored separately and indexed for search.

Frontend shorthand parsing may be supported later without compromising the normalized database structure.

## D012 — Image Storage

Do not plan to store the main examination image collection permanently on the VPS filesystem.

Production storage target:

```text
DigitalOcean Spaces
```

The Space should be private.

Laravel's filesystem abstraction should be used so storage remains replaceable.

Google Drive was considered but is not the preferred production storage architecture.

## D013 — Image Optimization

Compress/resize images on the client before normal upload wherever practical.

Reason:

Field agents may use variable mobile connections. Uploading an optimized approximately 500–800 KB photograph is generally preferable to transmitting an original 4–8 MB photograph and compressing it afterward.

Image quality and evidence readability take priority over a strict file-size target.

The exact compression algorithm and parameters must be tested using real examination photographs.

## D014 — Background Image Upload

Where practical, begin processing/uploading a selected image before the user presses the final Submit button.

Goal:

```text
Agent fills form
      +
images upload progressively
      |
      v
Final submission has minimal waiting
```

Direct upload to object storage may be implemented later using temporary/presigned authorization from Laravel.

## D015 — Officer Image Presentation

Do not design the officer workflow around small thumbnails requiring individual opening.

Selected examination records should populate large images directly, normally in a two-column grid on desktop.

Optional fullscreen/zoom viewing may supplement this presentation.

## D016 — Protected Examination Evidence

Guest submission is public-facing.

Examination retrieval and evidence viewing are not.

Submission numbers must not act as authorization tokens granting public access to examination data or images.

## D017 — Customs Form Parser Duplicate Input

**Superseded by D023.** The customs form parser must reject duplicate normalized numbers rather than silently de-duplicating them. This includes duplicates introduced by shorthand expansion, and parsing remains atomic when any token fails.

The rigid `B` + 11-digit syntax and comma-separated shorthand-expansion parser this decision described no longer exist as of D023 (customs form numbers are now free-form, one input per number, entered via repeatable form fields) — kept here for historical context only, not as a currently active rule.

## D018 — Submission Number Format and Generation Strategy

Every successfully submitted examination receives a human-readable submission number in the fixed format:

```text
ZB-YYMMDD-NNNN
```

Example:

```text
ZB-260908-0001
```

`ZB` is a fixed prefix. `YYMMDD` is the business date. `NNNN` is a four-digit daily sequence starting at `0001`, resetting every business date, with a hard maximum of `9999`.

### Business timezone stays separate from the application timezone

`config('app.timezone')` remains `UTC`. General application timestamps (`created_at`, `updated_at`, logs, etc.) are not affected by this feature.

A dedicated `config('zb-examine.business_timezone')` value (env `BUSINESS_TIMEZONE`, default `Asia/Kuala_Lumpur`) governs only the submission-number business date and daily sequence bucket. `App\Services\SubmissionNumberGenerator` reads this config value rather than hard-coding a timezone string.

### Dedicated counter table

A `submission_sequences` table holds one row per business date (`sequence_date` DATE UNIQUE, `last_number` UNSIGNED SMALLINT). Deriving the sequence from `COUNT`/`MAX` over `examinations` was rejected because it cannot be made race-free without effectively reinventing the same locking this table provides.

`examinations.submission_no` remains `UNIQUE` and is not weakened. That constraint is the final database safety net; the generator's own locking is the primary correctness mechanism.

### Concurrency strategy

`SubmissionNumberGenerator::generate()` allocates a number inside a single MySQL transaction:

1. An atomic no-op upsert (`INSERT ... ON DUPLICATE KEY UPDATE` via Laravel's `upsert()`) guarantees the day's row exists without a duplicate-key race, safe even on the first allocation of a new date.
2. `SELECT ... FOR UPDATE` (`lockForUpdate()`) takes an exclusive row lock on that date's row, serializing concurrent callers for the same business date.
3. `next = last_number + 1` is computed and written back inside the same locked transaction.
4. If `next > 9999`, `App\Exceptions\SubmissionNumberSequenceExhausted` is thrown and the transaction rolls back untouched.

`count(examinations) + 1` and unlocked `max(...) + 1` are explicitly rejected as unsafe under concurrency. This was verified with a real MySQL 8.4 concurrency test (`tests/Concurrency`, run via `phpunit.concurrency.xml` against the isolated `zb_examine_test` schema) using `pcntl_fork()` to run many genuinely competing OS processes — not a simulated/mocked test.

### Gap policy — numbers are never reused, and this is enforced

Once allocated, a submission number is permanently consumed, even if the examination that requested it later fails to persist. Gaps in the daily sequence are expected and acceptable; these are operational identifiers, not legal/invoice sequence numbers.

`SubmissionNumberGenerator::generate()` refuses to run if the connection already has an active transaction (`DB::connection()->transactionLevel() > 0`), throwing `App\Exceptions\SubmissionNumberAllocationInsideTransaction` (a `LogicException` — programmer/integration misuse, not a user-facing error). This is an enforced runtime guard, not merely a documented convention: without it, Laravel would silently open a SAVEPOINT for a nested `generate()` call, and an outer transaction's rollback would undo the "allocated" number, contradicting the invariant above.

Integration rule for the future examination submission service: allocate the submission number first and let that transaction commit, then start a separate transaction to persist the `Examination`. Calling the generator from inside a larger examination-persistence transaction on the same connection will throw `SubmissionNumberAllocationInsideTransaction` rather than silently risking an unsafe rollback.

### Exhaustion is machine-readable only

`SubmissionNumberSequenceExhausted` exposes an error code and the business date only. It must not contain Malay/English frontend copy; the eventual frontend maps the error code to a translated message via Laravel's translation keys (`ms` default, `en` fallback).

## D019 — Examination Submission Pathway

`App\Services\ExaminationSubmissionService::submit()` is the single pathway that creates a complete examination submission. It composes the customs-form-number normalizer (D023), the submission-number generator, and photo finalization (D022); it does not reimplement any of them, does not inspect `Auth`, and does not handle HTTP, Livewire state, or translations.

### Fixed operation order

```text
capture one instant
        |
        v
normalize/validate customs form numbers
        |
        v
allocate submission number   <- commits on its own
        |
        v
DB::transaction
    +-- examinations row
    +-- examination_customs_form_numbers rows
        |
        v
return Examination
```

**Normalization/validation happens before allocation.** Customs-number collection errors (empty, duplicate, oversized) must never consume a submission number. (This step was originally a rigid-syntax parser; see D017/D023 — the invariant that it precedes allocation is unchanged, only the validation rules themselves changed.)

**Allocation commits before persistence.** The generator is called outside the examination transaction, so the permanent-gap rule from D018 survives any subsequent persistence failure. Consequently `submit()` must not itself be wrapped in an existing database transaction — the generator's `SubmissionNumberAllocationInsideTransaction` guard enforces this at runtime rather than by convention.

### One captured instant

`submit()` captures a single immutable UTC instant at entry and passes that same physical instant to both `SubmissionNumberGenerator::generate()` and `examinations.submitted_at`. A submission occurring at the business-timezone midnight boundary therefore cannot receive a number bucketed to one business date and a timestamp belonging to another.

`submitted_at` is persisted in UTC like every other application timestamp. Only the `ZB-YYMMDD` bucket uses `config('zb-examine.business_timezone')`.

The instant is an optional injectable parameter, so boundary behaviour is testable without mutating global time.

### Snapshots come from submitted data

All five `agent_*` columns are written from the submitted data only. The service never reads values from the `User` record, so guest and registered submissions behave identically and historical records stay frozen when a profile later changes (D010). Profile auto-fill is a future form/UI concern.

The caller supplies the user context explicitly; `user_id` is `null` for guests. The submission DTO deliberately carries no `user_id`, so form input can never mass-assign record ownership.

### Atomic examination persistence

The `examinations` row and all `examination_customs_form_numbers` rows are created in one transaction. If any insert fails, the examination and every child row roll back together while the allocated submission number stays consumed. Normalizer, generator and database exceptions propagate unwrapped — no submission-specific exception type exists, because the existing ones are already specific and machine-readable.

### `display_order` is 1-based project-wide

`display_order` is a human/domain ordinal, not a PHP array index. The first child row is `1`. This applies to `examination_customs_form_numbers` now and to `examination_photos` when photos are implemented.

### `*_other` canonicalization is enforced before persistence

`App\Data\ExaminationSubmissionData::fromValidated()` canonicalizes the conditional fields: `form_type_other` is retained only when `form_type` is `other`, and `reason_other` only when `reason` is `other` (so a `null` or non-`other` reason always yields a `null` `reason_other`). A blank or whitespace-only `form_type_other` / `reason_other` also normalizes to `null`.

This nulling applies to those two conditional fields only. The required fields (`agent_name`, `agent_phone`, `agent_code`, `agent_company_name`, `agent_station_code`) are trimmed but never converted to `null`; their validity remains the responsibility of the validation layer.

This is a persistence-layer invariant, not merely UI validation, so stale hidden-form values cannot reach the database regardless of caller. The future Livewire/FormRequest layer still enforces `required_if` rules and provides localized error messages.

## D020 — Photo Upload Domain Foundation (Step 3B.1)

Photos upload before an `Examination` exists. Temporary upload state lives in two new tables,
`photo_upload_sessions` and `photo_uploads`, kept entirely separate from `examination_photos`
(the final evidence table, unchanged, unaltered by this decision). `App\Models\PhotoUploadSession`
and `App\Models\PhotoUpload` are the corresponding models. No HTTP endpoints, storage transport,
or `ExaminationSubmissionService` integration exist yet — this decision covers schema/domain
shape only.

**Bearer token, never persisted in plaintext.** A session's bearer secret is generated via
`Str::random(64)` and only its `hash('sha256', ...)` digest is stored in `token_hash` (unique).
The raw token is returned to the caller exactly once, by the sole canonical creation path,
`PhotoUploadSession::issue()`, and is never written to any column. This mirrors Laravel
Sanctum's own personal-access-token pattern.

**Public ULIDs are identifiers, not credentials.** Both tables carry a separate `public_id`
(ULID, unique) for anything externally referenceable later (object paths, API payloads).
Primary keys remain plain bigint auto-increment, consistent with every other table in this
project — ULIDs are assigned via a `creating` model event, not Laravel's `HasUlids` trait
(which would make the ULID the primary key).

**Finalization has exactly one signal.** `photo_upload_sessions.examination_id` (nullable,
unique, `cascadeOnDelete`) is the sole finalization signal: `NULL` = temporary/unclaimed,
set = finalized. There is no separate `finalized_at`/`status` column — redundant with the fact
the FK already carries unambiguously.

**Upload readiness has exactly one signal.** `photo_uploads.verified_at` (nullable timestamp)
is the sole lifecycle signal for an individual upload: `NULL` = pending/not yet server-verified,
set = server-verified and finalization-eligible. The schema itself does not guarantee that
`mime_type`/`file_size`/`width`/`height` are non-null or genuinely verified merely because
`verified_at` is set — that completeness invariant is enforced by the future Step 3B.2
server-side upload-completion verification before `verified_at` is ever written, not by a DB
constraint. There is no `PhotoUploadStatus` enum and no `status` column — client-side states
(`Processing`/`Uploading`/`Failed`/`Retrying`) are UI-only concepts that must not be persisted
unless a later phase demonstrates a genuine server-side need. A removed, unfinalized upload is
hard-deleted; there is no `removed` status.

**Storage paths are immutable once assigned.** `photo_uploads.storage_path` is set once at
upload-authorization time and never changes. No object copy/move ever happens during
finalization — the same path a `photo_uploads` row used is the path `examination_photos` will
reference after finalization copies only database metadata (Step 3B.2+), never the object
itself.

**The session row is the future mutex.** `photo_upload_sessions` is documented (in code and
here) as the serialization point for every future state-changing operation on a session or its
uploads — add/authorize, mark-verified, remove, reorder, finalize. Every such operation must
`lockForUpdate()` this row inside a transaction before mutating anything, and no
object-storage/network call may happen while that lock is held. No repository/helper class
enforces this yet; it is a documented convention until Step 3B.2 introduces real call sites.

**Maximum 10 photos is an application invariant, not a DB constraint.** A relational unique/
check constraint cannot cleanly enforce "at most 10 child rows." The future rule: inside the
session-row lock, count existing `photo_uploads` for that session and reject before inserting
an 11th — never an unlocked count-then-insert.

**`display_order` stays 1-based and index-only.** Both new tables use 1-based
`unsignedTinyInteger display_order`, indexed but not uniquely constrained per session/
examination — identical to the existing `examination_photos`/`examination_customs_form_numbers`
precedent, because reorder flows need to pass through states that would transiently violate a
strict uniqueness constraint.

**Fixed, non-sliding 24-hour expiry.** `photo_upload_sessions.expires_at` is set once, 24 hours
after creation, by the canonical `PhotoUploadSession::issue()` method, and is never extended by
later activity. A future abandoned-cleanup command selects only
`examination_id IS NULL AND expires_at < now()` — a finalized session can never match this
predicate regardless of how old `expires_at` is.

**Mass assignment is fully closed on both models.** `$fillable = []` on both
`PhotoUploadSession` and `PhotoUpload` — every column is set via direct property assignment in
a controlled domain method (`PhotoUploadSession::issue()`) or, in tests, via factories (which
Laravel intentionally exempts from mass-assignment guarding). No controller may ever pass raw
request input directly into `create()`/`fill()` for these models.

## D021 — Photo Upload Session HTTP API (Step 3B.2)

The upload-session lifecycle (create → allocate → upload → complete → resume → remove) is
implemented as six JSON endpoints under the normal `web` middleware group, backed by a local
private filesystem transport. Builds on D020's schema without altering it.

**Bearer-token authentication and CSRF are separate, complementary protections.**
`X-Photo-Upload-Token` proves ownership of one anonymous upload session regardless of cookies;
Laravel's standard `web`-group CSRF verification is **not exempted** for these routes — it
still defends against a cross-origin page riding an authenticated cookie session, an entirely
different threat. `App\Services\PhotoUploadSessionResolver` is the single place token
authentication happens: a combined `WHERE public_id = ? AND token_hash = ?` query means a
wrong token and a nonexistent/mismatched `public_id` are indistinguishable from the outside
(both simply match zero rows) — never a routing-layer 404 for one and an app-layer 401 for the
other, since these routes take plain string parameters, not implicit Eloquent model binding.

**`resolveLocked()` is the only entry point for mutation**, and is the mandated
session-row-mutex: it must run inside an active `DB::transaction()`, `lockForUpdate()`s the
parent `photo_upload_sessions` row, then re-checks expiry/finalization authoritatively. No
mutating operation (allocate/complete's write phase/remove) ever performs a read then trusts it
across a later lock — each re-queries fully once the lock is held. No filesystem or network
call ever happens while that lock is held; storage-plane work (`PhotoUploadTransport::store/
verify/delete`) always happens strictly before or after the locked section, never inside it.

**Removal is row-first, object-second, never the reverse.** The `PhotoUpload` row is
hard-deleted inside the locked transaction and committed; only afterward is the storage object
best-effort deleted. This ordering means a concurrent finalization attempt can never observe a
row whose physical object is mid-deletion. If the post-commit object delete fails, it's logged
as an orphaned private object — the row is never recreated and no compensating transaction is
attempted; an unreferenced private object is an acceptable, reconcilable state, while an
Examination referencing deleted evidence is not. **Step 3B.5**'s future cleanup command must
cover both ordinary expired-session sweeping and this orphaned-object reconciliation.

## D022 — Real Examination Form Integration + Atomic Photo Finalization (Step 3B.4)

The real, guest-facing `examinations.create` form now mounts the Step 3B.3 photo widget, and
`ExaminationSubmissionService::submit()` atomically finalizes 1-10 already-uploaded, verified
photos alongside the `Examination`/customs rows it already created (D019). Builds on D019/D020/
D021 without weakening any of their guarantees.

**Photo-first, upload-before-submit.** Photos are uploaded to a `PhotoUploadSession` (D020/D021)
entirely before the Examination form is ever submitted. Finalization only ever reads metadata
that Step 3B.2's `complete()` already verified against real bytes — it never touches storage.

**Extended, still-fixed operation order.**
```text
capture one instant
        |
        v
parse customs form numbers
        |
        v
unlocked photo-session precheck   <- optimization only, never authoritative
        |
        v
allocate submission number        <- commits on its own, unchanged from D019
        |
        v
DB::transaction
    +-- lock PhotoUploadSession parent row FOR UPDATE
    +-- authoritative session + fresh-child-query revalidation
    +-- examinations row
    +-- examination_customs_form_numbers rows
    +-- examination_photos rows (metadata-only copy)
    +-- photo_upload_sessions.examination_id claim
        |
        v
return Examination
```
Parsing customs numbers still strictly precedes both the photo precheck and number allocation —
an invalid parser input never touches the photo session at all, matching D019's existing
"parsing before allocation" guarantee extended one step further left.

**The unlocked precheck is an optimization, never authoritative.** `PhotoUploadSessionFinalizer::
precheck()` gives a fast, DB-only rejection (invalid/expired/finalized session, photo count
outside 1-10, any unverified photo) before a submission number is ever spent, mirroring the
existing `PhotoUploadSessionResolver::resolve()` (unlocked) / `resolveLocked()` (authoritative)
split from D021. Nothing precheck loads — session, photo rows, counts — is ever reused later.

**The parent `photo_upload_sessions` row lock is the finalization mutex, acquired before the
Examination is created.** Inside the single finalization transaction, `PhotoUploadSessionFinalizer
::lock()` calls the existing `resolveLocked()` first (D021's mandated session-row mutex,
unchanged), then issues a **fresh** `photo_uploads` query — ordered by `display_order`, then `id`
as a stable secondary key — only after the lock is held, and re-validates count (1-10) and
"every row verified" authoritatively against that fresh result. The precheck's loaded rows are
never substituted in here. This is the same discipline D021 already established for allocate/
complete/remove: acquire the lock first, then read/write, never trust a pre-lock read.

**Examination + customs rows + examination_photos + session claim commit together, in one
transaction.** If any part fails, all of it rolls back — including the session claim — while
the already-allocated submission number stays permanently consumed. D018/D019's permanent-gap
policy is unchanged; its scope of "things that can fail after allocation" now also covers photo
finalization.

**No object move/copy at finalization; temporary vs permanent is DB state, not storage path.**
`examination_photos.storage_disk`/`storage_path` are copied verbatim from the corresponding
`photo_uploads` row — the same physical object, never touched, moved, or re-uploaded.
`display_order` is renumbered 1-based/contiguous from the locked, freshly-queried, ordered
`photoUploads` at finalize time — never copied verbatim from `photo_uploads.display_order`,
which may have gaps after mid-session removals (D020 already documented this gap as expected).

**`photo_upload_sessions.examination_id` (already unique, from D020) remains the sole
finalization signal and the sole concurrency-safety mechanism.** No new locking primitive was
introduced: a second concurrent submission for the same session blocks on the same row lock,
then observes `examination_id` already set and is rejected with the existing `session_finalized`
code (D021) — at most one `Examination` is ever created from one photo session. This was
verified with a real MySQL 8.4 concurrency test (`tests/Concurrency/
DuplicatePhotoSessionSubmitTest`, run via `phpunit.concurrency.xml`), the same `pcntl_fork()`
genuinely-competing-OS-process methodology as D018/D019's submission-number concurrency test.

**Raw bearer token stays outside the domain DTO and is never flashed.** A small
`App\Data\PhotoUploadSessionCredentials` readonly value object (`publicId`, `token`) carries the
token from the request straight into `ExaminationSubmissionService::submit()`'s second
parameter — it is never part of `ExaminationSubmissionData` and never persisted anywhere. Two
independent no-flash mechanisms are required, not one: `bootstrap/app.php`'s
`$exceptions->dontFlash(['photo_upload_token'])` covers only Laravel's automatic
`ValidationException` redirect path; `ExaminationController`'s own manual `back()->withInput()`
catch-block redirects are a separate code path entirely unprotected by `dontFlash()`, so a
dedicated `redirectBackWithInput()` helper explicitly excludes `photo_upload_token` via
`$request->except(...)` on every manual redirect. Blade never calls `old('photo_upload_token')` —
the hidden field is populated only by client JS from the live `PhotoUploadManager` session.

**Ordinary non-photo validation failures never finalize or destroy the photo session.** Because
hidden fields are repopulated by JS fresh on every submit attempt (never via `old()`), and
`sessionStorage` is only ever cleared on the success page (never pre-submit), a validation
failure on an unrelated field redisplays the form with the same still-live photo session ready
to resume — no forced re-upload.

**BFCache/back-button hardening.** The success page clears only the namespaced/versioned photo
session `sessionStorage` key once a submission has genuinely succeeded. The create page adds a
`pageshow` listener that force-reloads on `event.persisted === true`, so a browser-restored page
never presents a stale, possibly-already-finalized session as submit-ready. `PhotoUploadManager
.resumeSessionIfAvailable()` was also extended to check the resume response's `finalized` flag
and clear stale credentials rather than resurrecting them. The server's authoritative
`session_finalized` rejection remains the final backstop regardless of any client state.

**No schema migration.** `photo_upload_sessions.examination_id` (D020) and `examination_photos`
(pre-existing, unmodified by D020/D021) already carried every column this step needed.

**Photo domain error codes extend, rather than duplicate, D021's existing structures.** Two new
`App\Exceptions\PhotoUploadInvalid` codes — `photo_count_invalid`, `unverified_photo_pending` —
cover finalize-time-only checks; the existing `PhotoUploadSessionInvalid` codes
(`invalid_session`/`session_expired`/`session_finalized`) are reused unchanged for session-level
rejection. No new exception hierarchy was introduced.

**`PhotoUpload` rows/objects are left in place after finalization, not deleted or moved.** A
finalized session's `photo_uploads` rows become historically vestigial once `examination_photos`
is the authoritative evidence copy, but pruning them (or their now-doubly-referenced storage
objects) is explicitly deferred to **Step 3B.5**, alongside the existing orphaned-object/
expired-session cleanup scope from D021.


**`complete` is idempotent by design.** A photo already `verified_at`-set returns its existing
state unchanged on a repeat call — no metadata rewrite, no `verified_at` bump — so a client
retrying after a lost response never causes a second write. Metadata
(`mime_type`/`file_size`/`width`/`height`) is always re-derived from the actual stored object
(`exif_imagetype()` for magic-byte MIME confirmation, `getimagesize()` for dimensions and a
second MIME cross-check, real `Storage::size()` for byte size) — never trusted from the client,
even though nothing in the request body can currently supply those fields anyway.

**Stable JSON error contract:** `{"message": "...", "code": "..."}` with nine codes across two
exception classes (`PhotoUploadSessionInvalid`: `invalid_session`/`session_expired`/
`session_finalized`; `PhotoUploadInvalid`: `photo_limit_reached`/`invalid_photo`/
`photo_too_large`/`photo_not_found`/`upload_not_ready`/`photo_state_conflict`), each owning its
own HTTP status mapping, rendered centrally in `bootstrap/app.php` rather than duplicated per
controller. `upload_not_ready` (genuinely incomplete — `complete` before any upload exists) and
`photo_state_conflict` (a settled state being contradicted, e.g. re-uploading an already
verified photo) are deliberately distinct codes, not one reused for both directions.

**A `storage_path` object is write-once: published exactly once, never overwritten
afterward.** An earlier version of `LocalPhotoUploadTransport::store()` used a plain
overwrite-capable `Storage::put()`, which permitted a retried/racing `upload` call to silently
overwrite or (via a since-removed cleanup step) delete an object a concurrent `complete()` had
already verified — a confirmed, blocking defect closed before this decision was recorded.
`store()` now writes a complete temporary file first, then publishes it to `storage_path` via
`link()` — atomic with respect to destination existence on POSIX: it either creates the
destination in one syscall or fails leaving it completely untouched, so the final path is never
overwritten, never visible half-written, and a losing request's own temp file is the only thing
it ever deletes. If `storage_path` already exists, the request is rejected with the existing
`photo_state_conflict` code — no new error code was introduced. `PhotoUploadService::upload()`
correspondingly performs **no** reconciliation/delete-on-state-change after a successful
publish: once published, the object is preserved regardless of what happens afterward
(concurrent `complete()`, concurrent `remove()`, session expiry/finalization racing in) — an
unreferenced private orphan is an acceptable, Step 3B.5-reconcilable outcome; deleting evidence
another request may have already accepted is not. **Any future transport implementation
(including Step 3B.6's Spaces transport) must preserve this same write-once invariant** — a
previously published object for a given `PhotoUpload` must never be overwritten.

**A failed verification is not proof that a published object is safe to delete.**
`PhotoUploadService::complete()` originally called `bestEffortDelete()` whenever
`transport->verify()` threw — but a concurrent `complete()` request may already have
successfully verified the exact same immutable object and captured its metadata, and could
still commit `verified_at` for it after the failing request deletes it out from under that
commit. `complete()` now leaves both the `PhotoUpload` row (`verified_at` stays null) and the
storage object completely untouched on any verification failure — the request fails exactly as
if it had never happened, and is safely retryable. Only two things may ever delete a published
object: an explicit `remove()` (row-first under the session lock, object best-effort deleted
only after commit) or a future Step 3B.5 reconciliation pass. This matters even more for a
future remote transport (Step 3B.6's Spaces implementation), where verification can fail for
purely transient transport/network reasons that say nothing about the object's own integrity —
`bestEffortDelete()` is reserved for paths where DB ownership/reference has already been
removed (`remove()`) or deletion is otherwise provably safe, never for a bare verification
failure.

## D023 — Free-Form Repeated Customs Form Numbers (Step 3B.4 refinement)

Customs form number formats vary by form type (K1/K2/K3/K8, Attachment A/uCustoms/ATA Carnet
each have their own real-world numbering conventions) — the old rigid `B` + 11-digit syntax with
comma-separated shorthand expansion (D017) modeled only one of these formats and is retired.
**Supersedes D017's active rule** (D017 itself is kept as historical record, not deleted).

**One UI input = one opaque customs form number.** The real form now submits
`customs_form_numbers[]`, an array with one value per repeatable input, instead of a single
comma-separated string. Each value is treated as an opaque business identifier at the
persistence layer — no `B` prefix, no digit-count, no comma parsing, no shorthand expansion.
Examples that are all equally valid: `B18106028839`, `ABC/2026/123`, `K8-123456`,
`UCUSTOMS-ABC-99`, `ATA 123/2026`.

**`App\Services\CustomsFormNumberNormalizer` replaces `App\Services\CustomsFormNumberParser`.**
`normalize(array $numbers): array` requires at least one value, trims each value, rejects empty
values, rejects values over 100 characters, rejects duplicates (trimmed, case-insensitive
comparison), preserves the submitted value's original trimmed casing for storage, and preserves
input order. It reuses the existing `App\Exceptions\InvalidCustomsFormNumberInput` exception
(cleanly represents collection-level errors; no new exception hierarchy needed) with a reduced
set of codes: `empty_input`, `empty_token`, `value_too_long`, `duplicate_number`. The obsolete
`invalid_number`/`missing_base_number` codes and their translations no longer exist.

**Duplicate semantics: trim + case-insensitive comparison, but storage preserves the exact
submitted (trimmed) value.** `B18106028839` and `b18106028839` are duplicates; `" B18106028839 "`
and `"B18106028839"` are duplicates. `k8-AbC-123` is stored exactly as `k8-AbC-123` — the
normalizer never uppercases/lowercases a value, only trims it.

**`examination_customs_form_numbers.number` is `VARCHAR(100)`**, changed from the old fixed
`CHAR(12)` via a normal schema migration (`Schema::table(...)->change()`, no `doctrine/dbal`
dependency needed — Laravel 11+ generates native `ALTER`/`MODIFY` statements). The existing
`unique(examination_id, number)` constraint and the `number` index are preserved unchanged by
the column-type change.

**Domain-service validation remains authoritative, not merely FormRequest convenience.**
`ExaminationSubmissionRequest` validates `customs_form_numbers` as `required|array|min:1|max:255`
and each `customs_form_numbers.*` as `required|string|max:100|distinct:ignore_case` (basic
shape/array-level validation, `prepareForValidation()` trims each value but never deletes/
collapses empty or duplicate entries — those must fail validation, never be silently discarded).
`ExaminationSubmissionData` now carries `customsFormNumbers` as a plain ordered array of strings,
never a raw comma-separated string. `ExaminationSubmissionService::submit()` still calls the
normalizer as the first and only source of truth for the actual persisted values — since the
service can be invoked directly (tests, future callers) bypassing the FormRequest entirely, the
normalizer is not merely a defense-in-depth duplicate of the FormRequest's rules, it is the
authoritative gate. This preserves D019's operation order exactly: normalize/validate customs
numbers still strictly precedes both the photo-session precheck and submission-number
allocation (D022); a customs-number collection error still consumes no submission number and
never touches the photo session.

**`display_order` remains 1-based and contiguous**, assigned from the normalizer's returned
array position — unchanged from D019's original behavior, just now driven by the normalizer's
ordered output instead of the old parser's ordered output.

**Client UX: Add clones the previous row's current value for fast sequential entry.** Clicking
Add appends a new `customs_form_numbers[]` input prefilled with the last input's current value,
focused, with the caret placed at the end — so an agent entering a sequential run of numbers can
just edit the trailing digits. This intentionally creates a temporary client-side duplicate;
duplicate UI validation only runs on blur/remove/submit, never immediately on Add, so the
expected clone-then-edit workflow never flashes a spurious error. Client-side duplicate
comparison (`trim().toLocaleLowerCase()`) is UX-only — the server/domain normalizer remains
authoritative regardless of what the client believes. The first row can never be removed;
additional rows have a Remove action. Old input reconstructs one input per submitted value after
a validation redirect (never collapsed back into a single field).

**Photo not-ready message simplified**, no behavior change: replaces the longer "please wait for
all photos to finish uploading (or remove them)" copy with a single sentence in both locales.

**"Lampiran A (Tarik Balik)" is an intentionally untranslated domain/formal term** and now
renders identically (same literal string) in both the `ms` and `en` locales, rather than being
translated to "Attachment A (Withdrawal)" in English.

**Locale switcher display simplified to `MY`/`EN`.** Internal locale codes (`ms`/`en`), URLs, and
session behavior are unchanged — this is a display-only label change for a more compact,
mobile-friendly control.

## D027 - Authenticated Private Spaces Evidence Preview

Finalized private evidence has two delivery modes. Local `photo_uploads` evidence
continues to stream privately through Laravel. Finalized
`photo_uploads_spaces` evidence is accessed only through the protected
`GET /examinations/{examination}/photos/{photo}/preview` route, which applies
authentication, scoped nested binding, and `ExaminationPhotoPolicy` before
returning a short-lived presigned GET URL in an HTTP 302 redirect. Guests are
redirected to login, agents receive 403, officers/admins are allowed, and a
cross-examination photo substitution returns 404.

Only this canonical finalized direct-upload path may be signed:

```text
photo-uploads/{session ULID}/{photo ULID}/{48-lowercase-hex}.jpg
```

Staging and malformed paths are rejected before presigning. The browser performs
the final provider GET; Laravel performs no provider HEAD and does not proxy or
download the Spaces object.

`PHOTO_PREVIEW_PRESIGN_TTL` is separate from the direct-upload
`PHOTO_UPLOAD_PRESIGN_TTL`. Its default is 120 seconds, with runtime bounds of
60 to 300 seconds; malformed configuration falls back to 120 seconds. The
signed `GetObject` request asks for `image/jpeg`, an inline server-generated
`evidence-{photo-id}.jpg` filename, and `private, no-store, max-age=0` cache
control. The application redirect is HTTP 302 with private no-store cache
control, `Referrer-Policy: no-referrer`, and `X-Content-Type-Options: nosniff`;
the redirect body is empty.

Expected AWS/configuration/signing failures are represented by the dedicated
`SpacesGetPresigningException` and mapped to
`FinalizedEvidenceDeliveryUnavailable`/opaque HTTP 503. Unexpected programming
errors are not swallowed as 503. A presigned URL may normally contain
`X-Amz-Credential`, `X-Amz-Signature`, and `X-Amz-Expires`; the secret access key
is never placed in the URL, and the full URL is never logged or persisted.

The accepted real-provider checkpoint is recorded as:

```text
Authenticated private Spaces evidence preview - PROVIDER QA PASSED
```

Against a real finalized private Spaces-backed `ExaminationPhoto`, officer and
admin preview passed, authenticated agent preview returned 403, guest preview
redirected to login, cross-examination substitution returned 404, the protected
route returned 302, the JPEG displayed from Spaces, unsigned object GET returned
AccessDenied, the 120-second URL expired as expected, the Laravel session
remained active after expiry, and a new application request issued a fresh
working URL. DigitalOcean Spaces honored the signed Content-Type,
Content-Disposition, and Cache-Control overrides. Storage-log inspection found
no signed URL query values. No URL, access-key identifier, signature, secret, or
temporary QA password is recorded here.

## D028 - Simple Username Staff Authentication and Session Lifetime

Staff authentication intentionally uses Laravel session authentication with
hashed passwords, CSRF, and the existing auth/guest middleware. The login
identifier is `username + password`, not email. The existing schema already has
a required unique username and a nullable unique email column, so no migration
was required; email remains optional data and is not used for authentication.

Usernames are canonicalized as `strtolower(trim(username))` when stored, when
login input is prepared, and when the login throttle identity is built. The
existing lightweight limiter remains five attempts per minute and keys by
normalized username plus IP. The intended security boundary is deliberately
simple: public users may submit examination forms, while internal
submission/evidence access requires authenticated authorized staff.

Email verification, password reset, 2FA, SSO, enterprise IAM, and complex
lockout/account-recovery flows are intentionally outside this application
contract.

The project default is `SESSION_LIFETIME=240`, a four-hour inactivity lifetime
for staff login sessions. This is independent of
`PHOTO_PREVIEW_PRESIGN_TTL=120`: a staff session may remain active for hours
while an individual private Spaces URL expires after approximately two minutes.

The accepted automated checkpoint for this correction is 17 focused
authentication tests with 85 assertions and 305 tests with 956 assertions in the
full regular suite. Existing local/Spaces preview regression coverage remained
green. This decision does not change the earlier direct-upload status:
**Core desktop E2E QA PASSED. Extended/mobile resilience QA DEFERRED.**

## D029 - Staff Examination Retrieval and Split Review Workspace

Internal examination retrieval is available only to authenticated `officer` and
`admin` users. `GET /examinations` is the paginated staff workspace index and
`GET /examinations/{examination}` is the selected-examination workspace state.
Guests are redirected to login and agents receive `403`. The show action
explicitly authorizes both collection access (`viewAny`) and access to the
selected examination (`view`); this remains a server-side boundary and is not
replaced by navigation visibility.

The staff search is one server-side query-string field covering only
`submission_no`, `agent_code`, `agent_station_code`, `agent_name`,
`agent_company_name`, and related customs form numbers. Raw input is validated
before trimming; malformed array input is rejected and whitespace-only input is
treated as no filter. Search results use grouped Eloquent conditions, deterministic
`submitted_at DESC, id DESC` ordering, and database pagination of 25 rows.
Searching always submits to `/examinations` and resets the selected detail. A
sidebar selection preserves only the current `search` and `page` context.

The split workspace was selected over the initially implemented standalone
list-to-detail presentation because it better supports repeated staff review.
The sidebar intentionally shows only submitted date/time and `submission_no`; the
entire row is clickable and uses semantic selected-row markers. The right pane
contains the complete read-only examination summary and ordered evidence.

At desktop widths, the detail summary is compact and capped at approximately 42%
of the right pane, with its own overflow, so the evidence region retains a
meaningful viewport. The evidence scroll viewport is deliberately separate from
the content-sized photo grid: the outer element owns `min-h-0`, `flex-1`, and
`overflow-y-auto`, while the inner element owns the grid and its natural rows.
This prevents multiple photo rows from being compressed into the available
viewport height.

Evidence thumbnails use a clickable 4:3 container with a neutral light background
and `object-contain` rather than `object-cover`. Evidence completeness takes
priority over crop-fill aesthetics, especially for portrait photographs. Both
thumbnail `src` and clickable `href` use only the existing protected Laravel
preview route; Blade never emits a Spaces URL, presigned URL, storage path, or
storage metadata.

The sidebar does not count or eager-load photos. The index does not eager-load
photo or customs-form collections. The show state loads only the selected
examination's ordered customs form numbers and photos. Below Tailwind `lg`, the
panels stack naturally; no separate mobile application/layout was introduced.

## D030 - Registered Agent Profile Defaults and Examination Snapshots

Registered Agent profile data is a form-default convenience layer only.
Persisted Examination agent fields remain immutable submission-time snapshots.
Profile values may prefill `agent_name`, `agent_phone`, `agent_code`,
`agent_company_name`, and `agent_station_code` on the public Examination form,
but the fields remain editable for each submission.

Old input takes precedence over profile defaults after validation failure, so a
round-trip never replaces the user's submitted value with a saved profile value.
`ExaminationSubmissionService` remains profile-agnostic and persists exactly the
validated `ExaminationSubmissionData` it receives; later User profile changes do
not rewrite historical Examination rows.

The profile is Agent-only self-service resolved from the authenticated session.
Officer/Admin users are not treated as Agents for autofill or profile navigation,
and there is no arbitrary user-id profile route. There is no profile-completeness
gate before submission. No migration was required because the existing `users`
schema already has `name`, `phone`, `agent_code`, `company_name`, and
`station_code`.
