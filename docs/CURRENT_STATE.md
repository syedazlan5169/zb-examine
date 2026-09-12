# ZB Examine — Current State

Last updated: 2026-09-11

## User and Account Management — Implemented Locally, Not Deployed

The User and Account Management module is implemented and tested in the local
repository after the currently deployed production release. It has not been
deployed to production.

The local implementation adds public self-registration, which always creates an
active `agent`, admin-only user management, all-role My Account access, password
changes, admin password resets, and account activation/deactivation. The only
schema change is the additive `users.is_active` boolean with a non-null `true`
default; existing users remain active and historical examination relationships
are preserved.

Role and activation changes use a transactional last-active-admin invariant with
deterministic MySQL row locking. Admins cannot demote or deactivate themselves,
the admin reset endpoint cannot reset the current admin, and deactivated users
are rejected at login and logged out by active-account middleware on their next
protected request. Security-sensitive administrative actions emit structured
application logs without passwords, hashes, tokens, or session identifiers.

Local verification now includes the dedicated real-MySQL concurrency suite:
`UserAdminInvariantConcurrencyTest` passed 2 tests and 9 assertions, covering
both competing demotions and the mixed deactivation/demotion race. The normal
local suite passed 393 tests and 1,308 assertions with zero failures or errors;
the frontend production build also passed. These results are local verification
only and do not represent a production deployment.

Forgot-password email recovery, email verification redesign, 2FA, SSO,
permissions packages, audit tables/packages, deletion, `last_login_at`, and
`password_changed_at` remain deferred. Production migration, preflight, and live
verification remain deployment work and have not been performed.

## Reports and Monthly Statement — Implemented Locally, Not Deployed

The staff-only Reports and Monthly Statement module is implemented and tested
locally. Officer and Admin users can view monthly dashboard statistics, daily
activity, Agent activity, a paginated monthly statement, and an XLSX export.
Agents remain denied by the existing `ExaminationPolicy::viewAny` boundary.

One successfully persisted `Examination` row is one official report record.
Reports use `examinations.submitted_at`, with half-open boundaries calculated in
`Asia/Kuala_Lumpur` and converted to UTC before querying. The four approved
headline metrics are Total Submissions, Unique Submitting Agents, Evidence
Photos from final `examination_photos`, and Average Submissions per Active Day.

Historical Agent identity is never read from the current User profile. Registered
submissions group by `user_id` and display the most recent snapshot in the
selected period; guest submissions group by normalized snapshot values. The
statement includes ordered customs form numbers and final evidence counts.

XLSX export uses OpenSpout 5.11.3, with `Summary` and `Monthly Statement`
worksheets. Export rows are generated in bounded 250-row keyset batches using
the selected period's initial `(submitted_at, id)` high-watermark, and all
textual database values use literal string cells to prevent spreadsheet formula
injection. Failed workbook generation removes its temporary file. No database
migration or Dockerfile change was required. The application minimum PHP
version is 8.4. These
results are local verification only and do not represent a production
deployment.

## P3 Production Edge Contract

Status: **Repository-side production edge ready; live activation deferred to P4.**

P3 defines the production environment, host-Nginx, trusted-proxy, and DigitalOcean
Spaces CORS contracts without deploying them. Laravel trusts only its immediate
FastCGI peer through Laravel 13's `REMOTE_ADDR` proxy token and consumes only the
forwarded client, host, port, and protocol headers established by the host edge.
The host-Nginx template rejects unknown hosts, redirects the exact HTTP hostname
to its fixed HTTPS equivalent, terminates TLS, overwrites public forwarding
headers, and proxies only to loopback-bound Docker Nginx at `127.0.0.1:8081`.

Nginx request-header forwarding to FastCGI was verified against the Nginx module
contract: it is enabled by default, and incoming HTTP headers are exposed as
`HTTP_*` FastCGI parameters. The existing Docker Nginx configuration therefore
requires no redundant forwarded-header mappings. PHP-FPM remains unpublished,
Docker Nginx remains loopback-only at the host boundary, and the host edge is the
authoritative header-sanitization boundary.

Production Compose interpolation and Laravel runtime values have separate,
placeholder-only templates under `deploy/env`. Future real files belong at
`/etc/zb-examine/compose.env` and `/etc/zb-examine/laravel.env`; the directory
should use mode `0750` and files `0640` with a deployment-user/group model, or
`0600` when Compose always runs through sudo. `APP_KEY` is generated exactly
once using `php artisan key:generate --show`, stored directly in the protected
runtime environment, shared by app and scheduler, retained across deploys and
rollbacks, and never committed, baked into an image, or generated during
startup. Initial production deployment continues without environment-specific
Laravel config caches.

The tracked Spaces CORS artifact is XML for DigitalOcean's documented
`s3cmd setcors deploy/spaces/cors.xml s3://space-probono-apps` workflow after the
example has been rendered outside Git. It permits only browser `PUT` requests from
`https://<production-domain>` with `Content-Type`, exposes no response headers,
and uses a 300-second production preflight cache. P4 may temporarily lower that
cache while validating changes. CORS does not grant object access: the bucket
remains private, CDN remains off, and authorization remains presigned staging
PUT, authenticated server SDK access, and short-lived signed preview GET.

Live activation remains blocked on the real production domain, a Let's Encrypt
registration/notification email if required, VPS deployment, DNS, certificate
issuance, and applying the Spaces CORS policy. P4 also owns migrations, backups,
UFW/SSH hardening, initial users, and live production verification. P3 does not
mean production is deployed.

**Core desktop E2E QA PASSED. Extended/mobile resilience QA DEFERRED.**

## Project Status

Initial Laravel and Docker development foundation is operational.

The current domain foundation includes the `Examination`, `ExaminationCustomsFormNumber`, and `ExaminationPhoto` models, related enums, and the customs form number normalizer.

The submission-number generator is implemented: `App\Services\SubmissionNumberGenerator` allocates unique `ZB-YYMMDD-NNNN` numbers backed by a dedicated `submission_sequences` counter table with MySQL row-level locking, using the `Asia/Kuala_Lumpur` business timezone (`config('zb-examine.business_timezone')`) independently of the application's UTC `config('app.timezone')`. See D018 in docs/DECISIONS.md.

The core examination submission pathway is implemented: `App\Services\ExaminationSubmissionService` creates one complete submission by composing the customs-form-number normalizer, the number generator, and photo finalization. See D019/D022/D023 in docs/DECISIONS.md.

The real Examination form, photo integration, customs-form-number UI, expired-session/orphan-object cleanup, simple staff authentication, staff retrieval workspace, authenticated private evidence preview, Registered Agent Profile + Examination Form Auto-Fill, Agent Submission History, and canonical Malay business vocabulary standardization are implemented. Authentication uses username/password; the Agent personal-history surface is now a distinct, owner-scoped feature separate from the staff workspace.

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
cancellation never enters this recovery path.

Step 3B.6C status: **Core desktop E2E QA PASSED. Extended/mobile resilience QA
DEFERRED.** The direct browser flow, real Spaces ownership handoff, one-photo
finalization, three-photo submission, ten-photo limit, and one refresh/active-
upload recovery scenario were verified. The remaining manual coverage is listed
in the QA checkpoint below; the automated/provider proofs from Step 3B.6B remain
valid.

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

## Step 3B.6C Real QA Checkpoint

Status: **Core desktop E2E QA PASSED. Extended/mobile resilience QA DEFERRED.**

The first real direct browser completion exposed a missing local migration:
`2026_09_10_000001_add_deletion_started_at_to_photo_upload_cleanup_queue_table`.
After the migration was run, completion could be resent successfully. That
session also exposed a production bug: `completeDirect()` moved the
`PhotoUpload` row from staging to a sealed path and removed the winner
candidate intent, but did not stage cleanup ownership for the old staging path.
The sealed object was correctly owned while the old staging object remained in
Spaces without a cleanup-queue owner. Commit `28bbccd` (`fix: preserve staging
cleanup ownership on direct completion`) corrected this without changing the
storage transaction boundary.

The fixed transaction locks the session and fresh pending row, captures the old
staging disk/path, locks the sealed candidate intent, stages the old staging
cleanup intent, persists the sealed path and authoritative metadata including
`verified_at`, removes the winner candidate intent, and commits. No storage
operation occurs inside the transaction. On rollback, the staging intent rolls
back, the upload remains pending and staging-owned, and the candidate intent
remains durable.

Automated validation after the fix:

```text
regular suite:         265 tests, 779 assertions, 0 failures
real MySQL concurrency: 27 tests, 197 assertions, 0 failures
```

Concurrency assertions check ownership roles and exact paths rather than brittle
global cleanup-row counts.

### Core desktop and provider evidence

Fresh fixed-code one-photo proof used an Incognito browser session. The observed
sequence was: session creation `201`, allocation `201`, staging authorization
`200`, Spaces CORS preflight `200`, browser XHR PUT `200`, and Laravel complete
`200`. The UI ended at `Uploaded`.

The direct Spaces PUT contained `Content-Type: image/jpeg` and no Laravel
Authorization, CSRF, or Cookie header. The authorization response exposed only
transient staging authorization; it exposed neither the sealed path nor raw
Spaces credentials.

Verified one-photo state used `photo_uploads_spaces`, a sealed `photo-uploads/...`
path, JPEG metadata, `1024 x 1024`, file size `182468`, and non-null
`verified_at`. Exactly one old staging cleanup intent existed with
`deletion_started_at = NULL` and `attempt_count = 0`; no cleanup intent existed
for the claimed sealed winner. Both sealed and staging objects temporarily
existed at size `182468`, as expected before the settling window elapsed.

The same photo was finalized into examination `ZB-260910-0001` (`id = 10`).
`ExaminationPhoto` exactly matched the sealed `PhotoUpload` path and metadata,
with display order `1`; no staging path appeared in finalized evidence. An
unauthenticated HTTPS request for the sealed object returned HTTP/2 `403`.

Three-photo proof succeeded as `ZB-260910-0002`: three distinct sealed paths,
display orders `1, 2, 3`, and `photo_uploads_spaces` for every row. Ten-photo
stress proof succeeded as `ZB-260910-0003`: ten distinct sealed paths, display
orders `1` through `10`; photo 11 was rejected while the existing ten remained
intact and the UI remained responsive.

With the browser throttled to 3G, a refresh during active uploads recovered two
photos as `Uploaded` and one as `Needs reselection` with `Complete/Re-check`.
Clicking `Complete/Re-check` without reselecting the file changed that row to
`Uploaded`. This is evidence for completion-first recovery only; it is not a
claim that the broader mobile fault matrix is complete.

The historical staging object created before the fix was manually reconciled:

```text
session: 01M23TPS6S6WKPTEYTH5ZTMPA2
photo:   01M23TPS795HQQ7ZP43D4PJ1T0
```

Only its exact cleanup intent was made due. Direct invocation of
`PhotoUploadCleanupService::processQueueUntilSettled(now(), 1)` reported
`queueRowsDue = 1`, `objectsCleared = 1`, `clearingFailures = 0`,
`integrityConflicts = 0`, and no failures. The historical queue row and staging
object were absent afterward; the already-owned sealed evidence was not deleted.

### Deferred manual QA

The following remain intentionally deferred and must not be represented as
covered by the desktop pass:

- real phone/LAN direct-upload QA and phone CORS exact-origin QA;
- additional locale-switch recovery and form-validation recovery;
- pending and verified removal scenarios;
- explicit cancellation during PUT;
- additional uncertain-PUT browser fault injection;
- abandoned-session cleanup;
- broader browser/server log privacy audit;
- optional CORS-failure and related manual edge tests.

After direct QA, the local default was restored to `PHOTO_UPLOAD_MODE=proxy` and
Laravel config cache was cleared. Runtime values are `photo_upload_mode=proxy`,
`photo_upload_disk=photo_uploads`, and
`photo_upload_direct_disk=photo_uploads_spaces`. `.env` remains local and
untracked; direct Spaces credentials/config remain available locally but are not
documented here.

## Authenticated Private Spaces Evidence Preview

Status: **Authenticated private Spaces evidence preview — PROVIDER QA PASSED.**

Finalized private evidence retrieval now supports both storage modes through the
protected application route:

```text
photo_uploads
  -> Laravel private streaming

photo_uploads_spaces
  -> authenticated application route
  -> scoped nested binding and ExaminationPhotoPolicy authorization
  -> short-lived presigned GET
  -> HTTP 302 redirect to the private DigitalOcean Spaces object
```

The route is:

```text
GET /examinations/{examination}/photos/{photo}/preview
```

Guest requests redirect to login, agents receive `403`, and officers/admins are
allowed. Scoped nested binding prevents cross-examination photo substitution;
the wrong examination/photo pair returns `404`.

Only this canonical finalized direct-upload path can be signed for retrieval:

```text
photo-uploads/{session ULID}/{photo ULID}/{48-lowercase-hex}.jpg
```

Staging and malformed paths are not retrievable. The preview TTL is configured by
`PHOTO_PREVIEW_PRESIGN_TTL`, defaults to `120` seconds, and is bounded at runtime
to a minimum of `60` and maximum of `300` seconds. Malformed configuration falls
back to `120`. This retrieval TTL is separate from
`PHOTO_UPLOAD_PRESIGN_TTL`, which authorizes direct-upload PUT requests.

Laravel does not perform a provider HEAD or proxy/download Spaces evidence. It
creates the signed request and the browser performs the final provider GET. The
signed GET requests `image/jpeg`, an inline server-generated filename of
`evidence-{photo-id}.jpg`, and `private, no-store, max-age=0` response cache
control. The application returns HTTP `302` with `Cache-Control:
private, no-store, max-age=0`, `Referrer-Policy: no-referrer`, and
`X-Content-Type-Options: nosniff`; the redirect body is empty.

Expected AWS/configuration/signing failures are translated through the dedicated
`SpacesGetPresigningException` into `FinalizedEvidenceDeliveryUnavailable` and
an opaque HTTP `503`. Unexpected programming errors are not swallowed as `503`
and may surface normally as `500`. Presigned URLs are temporary bearer access:
`X-Amz-Credential`, `X-Amz-Signature`, and `X-Amz-Expires` may normally appear,
but the secret access key is never placed in the URL. Full signed URLs are never
logged or persisted.

### Provider QA checkpoint

Manual testing used a real finalized private Spaces-backed `ExaminationPhoto`:

```text
officer login + preview                              PASS
admin login + preview                                PASS
authenticated agent preview -> 403                   PASS
guest preview -> login redirect                       PASS
cross-examination photo substitution -> 404           PASS
protected Laravel route -> 302 signed Spaces URL     PASS
JPEG display from real Spaces                        PASS
unsigned Spaces object GET -> AccessDenied            PASS
120-second signed URL expiry -> Request has expired   PASS
Laravel login remains active after expiry             PASS
new preview request issues a fresh working URL        PASS
```

DigitalOcean Spaces honored the signed response overrides:

```text
Content-Type: image/jpeg                              PASS
Content-Disposition: inline; filename="evidence-33.jpg" PASS
Cache-Control: private, no-store, max-age=0            PASS
```

Storage-log inspection found no `X-Amz-Signature` or `X-Amz-Credential`
presigned URLs. No actual URL, access-key identifier, signature, secret, or
temporary QA password is documented here.

## Staff Examination Retrieval and Split Review Workspace

Staff examination retrieval is implemented for `officer` and `admin` users only.
The official routes are:

```text
GET /examinations
  examinations.index

GET /examinations/{examination}
  examinations.show
```

Guests are redirected to login, agents receive `403`, and officers/admins are
allowed. The index authorizes `viewAny`; the show action explicitly authorizes
both `viewAny` for the examination collection and `view` for the selected
examination. The existing protected evidence preview route remains unchanged.

The sidebar search is server-side and supports:

```text
submission_no
agent_code
agent_station_code
agent_name
agent_company_name
customs form number
```

Raw search input is validated before trimming. Malformed array input is rejected,
whitespace-only input behaves as no filter, and grouped Eloquent conditions keep
the search bounded to those dimensions. Results are ordered by
`submitted_at DESC, id DESC` and paginated at 25 examinations per page. Search
submits to `/examinations` and resets the selected detail; selecting a sidebar
row preserves the current `search` and `page` context.

The accepted UI is a split review workspace. On desktop, the left sidebar
contains search, pagination, and clickable examination rows showing only the
business-timezone submitted date/time and `submission_no`. Agent/company fields,
agent/station identifiers, photo count, and a separate View button are not shown
in the sidebar. The selected row uses semantic markers including
`aria-current="page"` and `data-selected="true"` when it is present on the
current page.

`GET /examinations` renders the populated sidebar with no selected examination
and a localized right-pane prompt:

```text
Malay:   Pilih pemeriksaan untuk melihat butiran.
English: Select an examination to view details.
```

The right pane preserves the read-only submission, agent, examination, and
ordered customs-form information. Enum values remain localized, `form_type_other`
and `reason_other` are shown only for the corresponding `Other` values, and
`submitted_at` is displayed in `Asia/Kuala_Lumpur` while stored timestamps remain
UTC.

At desktop widths the detail summary is compact and capped at approximately 42%
of the right pane, with its own overflow. The evidence section owns the remaining
space. Its outer scroll viewport is separate from the inner content-sized grid:

```text
evidence flex container
  -> outer min-h-0 flex-1 overflow-y-auto viewport
  -> inner grid grid-cols-1 sm:grid-cols-2 content
```

This preserves natural photo-row sizing while allowing multiple rows to scroll
inside the evidence area. Evidence thumbnails use a clickable 4:3 container with
a neutral `bg-gray-50` background. Images use `h-full w-full object-contain` so
complete portrait evidence is visible rather than cropped. Both image `src` and
clickable `href` use only the protected application preview route:

```text
route('examinations.photos.preview', [$examination, $photo])
```

No Spaces URL, presigned URL, storage path, or storage metadata is emitted by the
staff Blade UI. Below `lg`, the panels stack naturally; extensive mobile
refinement is not part of this slice.

### Staff Retrieval QA Checkpoint

Manual browser QA **PASSED** for the staff retrieval feature. Confirmed manually:

```text
officer examination list loads                         PASS
partial submission number search                       PASS
selected examination details are correct               PASS
evidence thumbnails render                             PASS
protected thumbnail -> full Spaces evidence flow      PASS
English translation                                      PASS
Malay UI                                               PASS
admin behavior matches officer                         PASS
agent receives 403                                     PASS
guest redirects to login                               PASS
customs form search                                    PASS
no-results state                                       PASS
desktop split workspace                                PASS
sidebar selection populates right detail pane          PASS
two-column evidence gallery                            PASS
evidence area scrolls independently                    PASS
multiple photo rows retain thumbnail sizing            PASS
clicking thumbnails opens full evidence                PASS
object-contain thumbnails show complete portraits     PASS
light empty space around portrait images acceptable    PASS
split workspace preferred over standalone list/detail  PASS
```

Automated validation for the final staff slice:

```text
StaffExaminationRetrievalTest: 14 tests, 88 assertions, 0 failures
Relevant regressions:          80 tests, 313 assertions, 0 failures
Full regular suite:             319 tests, 1,044 assertions, 0 failures
```

Pint, Composer validation, and `git diff --check` passed. Extensive mobile QA was
not performed and is not represented as passed here. This staff UI checkpoint is
separate from the direct-upload checkpoint above, which remains exactly:

**Core desktop E2E QA PASSED. Extended/mobile resilience QA DEFERRED.**

## Registered Agent Profile + Examination Form Auto-Fill

Authenticated Agent users can manage their own profile through:

```text
GET   /profile  profile.edit
PATCH /profile  profile.update
```

The profile manages only these existing `users` columns:

```text
name
phone
agent_code
company_name
station_code
```

No database migration was required. The existing `users` table already provides
all five columns. There is no arbitrary user-id profile route; profile identity
is resolved only from the authenticated session.

Profile access is role-scoped: guests redirect to login, Agents are allowed,
and officers/admins receive `403`. The profile endpoint cannot change
`username`, `email`, `password`, `role`, `preferred_locale`,
`email_verified_at`, `remember_token`, or another user. Existing Laravel session
auth, CSRF, and password behavior remain unchanged.

Optional profile fields support incomplete records and may be cleared:

```text
phone
agent_code
company_name
station_code
```

There is no profile-completeness middleware, no complete-profile-before-
submission requirement, and no blocking redirect. The existing examination
submission validation remains authoritative.

### Profile defaults and submission snapshots

Registered Agent profile data is current convenience/default data only.
Persisted Examination agent fields remain immutable submission-time snapshots.

Profile values map to the public form as:

```text
User.name         -> agent_name
User.phone        -> agent_phone
User.agent_code   -> agent_code
User.company_name -> agent_company_name
User.station_code -> agent_station_code
```

When an authenticated Agent opens `GET /`, those values are applied as defaults
for the five Agent fields. Guests keep blank/manual fields, and officer/admin
users do not receive Agent profile autofill. The prefilled fields remain
editable, so an Agent may override a profile default for one submission without
changing their profile.

Old input takes precedence over profile defaults:

```text
old(field, profile-default)
```

Validation round-trips preserve the value the user typed; profile defaults must
not overwrite submitted input after validation failure. The shared text-input
component provides this behavior and was audited as safe for the current inputs.

`ExaminationSubmissionService` remains profile-agnostic.
`ExaminationSubmissionData` remains the canonical validated submission boundary.
The service continues persisting exactly what was submitted, and changing a User
profile later does not rewrite historical examinations.

### Navigation and localization

Header behavior is currently:

```text
Guest:
  login
  public form remains accessible

Agent:
  Profile
  Logout

Officer/Admin:
  staff examination workspace
  Logout
```

Officer/Admin users do not receive Agent Profile navigation, and Agents remain
denied from staff examination retrieval.

Profile UI is bilingual with Malay as the default and English optional.
Profile-specific strings live in `lang/ms/profile.php` and `lang/en/profile.php`;
existing examination field labels are reused where appropriate.

### Manual QA checkpoint

Manual browser QA **PASSED** for the Registered Agent Profile + Examination Form
Auto-Fill slice. Confirmed manually:

```text
Agent can open own Profile page                                      PASS
all five saved profile values render correctly                       PASS
profile update succeeds                                              PASS
localized success message appears                                    PASS
saved values remain after redirect/reload                            PASS
authenticated Agent public form auto-fills all five Agent fields     PASS
prefilled fields remain editable                                     PASS
Agent changed one prefilled Company value before submission          PASS
submission succeeded as ZB-260910-0004                               PASS
staff workspace showed the edited submitted Company value            PASS
Agent changed the saved profile Company value afterward              PASS
historical submission ZB-260910-0004 remained unchanged              PASS
guest public form remains blank/manual with no profile leakage       PASS
officer/admin public form does not receive Agent profile autofill    PASS
Malay profile UI works                                               PASS
English profile UI works                                             PASS
translated field labels/buttons/success message work                 PASS
no raw translation keys observed                                     PASS
```

The `ZB-260910-0004` check proves persistence uses submitted form data rather
than silently re-reading the User profile, and that the Examination snapshot is
independent of later profile changes.

Automated validation for this slice:

```text
Focused AgentProfileTest:              16 tests, 68 assertions, 0 failures
Relevant regression set:              110 tests, 469 assertions, 0 failures
Full suite before final formatting:   335 tests, 1,112 assertions, 0 failures
vendor/bin/pint --test:               PASS on all 5 touched PHP files
AgentProfileTest after Pint:           16 tests, 68 assertions, 0 failures
git diff --check:                     PASS
Composer validation:                  PASS
```

## Staff Authentication and Session Lifetime

Staff authentication is intentionally simple and uses Laravel session
authentication with hashed passwords, CSRF, and the existing `auth`/`guest`
middleware:

```text
username + password
```

Email remains in the users table as nullable/optional data and is not an
authentication identifier. The existing schema already provides a required,
unique `username`, so no migration was required. Usernames are canonicalized as
`strtolower(trim(username))` in model storage, login input, and the login
throttle identity. The limiter remains five attempts per minute and keys by
normalized username plus IP.

Email verification, password reset, 2FA, SSO, enterprise IAM, and complex
lockout/account-recovery flows are deliberately not implemented. Public users
may submit examination forms; internal submission/evidence access requires an
authenticated authorized staff role.

The project default is `SESSION_LIFETIME=240`, meaning a four-hour inactivity
lifetime for staff login sessions. This is separate from
`PHOTO_PREVIEW_PRESIGN_TTL=120`: a staff user may remain logged in for hours
while each individual private Spaces URL expires after approximately two
minutes.

Latest accepted automated checkpoint:

```text
focused authentication: 17 tests, 85 assertions
full regular suite:     305 tests, 956 assertions
```

Existing local/Spaces preview regression coverage remained green. The earlier
direct-upload status remains unchanged: **Core desktop E2E QA PASSED. Extended/mobile resilience QA DEFERRED.**

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

The create/store routes are guest-accessible; no authentication is required to submit. If a user happens to be authenticated, `auth()->user()` is passed to `ExaminationSubmissionService::submit()`. Authenticated Agents receive editable profile defaults for the five Agent fields; guests and officer/admin users do not receive Agent profile autofill.

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

The examination domain, its core submission pathway, the full guest-facing photo-integrated submission form, staff retrieval workspace, and Registered Agent Profile + Examination Form Auto-Fill are implemented and tested. Next work should build on top of the existing authenticated Agent surface.

Recommended next product milestone:

```text
Agent submission history
```

The overall photo-upload architecture is approved and integrated end-to-end (see D020/D021/D022/D024/D025/D026 in docs/DECISIONS.md). Photo capture, proxy/direct upload, atomic Examination finalization, durable cleanup, and authorized private preview retrieval all exist and are tested. Remaining photo-adjacent work includes the separate future policy for pruning finalized upload metadata and the deferred manual/mobile QA listed in the Step 3B.6C checkpoint.

Do not start implementing these blindly from assumptions.

The existing Google Form should be mapped and reviewed so the new application preserves required operational fields while improving weak parts of the previous workflow.

## Phase 1 — Routing, Landing Page and Role Homes

The root route is now authentication-aware. Guests receive a small localized
landing page at `/` with the official product name, New Submission, Login, and
Register actions. Authenticated Agents are redirected to the dedicated
Examination Submission form at `/examinations/create`; Officers and Admins are
redirected to `/examinations`.

The named `examinations.create` route now points to `GET /examinations/create`.
The public mutation contract remains unchanged: `POST /examinations`,
`GET /examinations/success`, and every temporary/direct photo-upload endpoint
retain their existing paths and names.

Default authenticated destinations are centralized in
`App\Services\RoleHomeResolver` and are reused by the root endpoint, login
success flow, registration success flow, and Laravel's authenticated-user
redirect from guest-only routes. Valid intended destinations continue to be
handled by Laravel's normal `redirect()->intended(...)` behavior; when there is
no intended destination, the role home is used.

The shared desktop navigation now exposes New Submission to guests and all
authenticated roles. The mobile hamburger/drawer remains deferred to the next
phase. Draft persistence, photo preview restoration, Remember Me, locale
preference persistence, profile enrichment, soft deletes, and broader UI
refinement remain future work.

## Phase 2 — Responsive Navigation Drawer

The shared application navigation now uses the existing `lg` breakpoint:
desktop navigation is visible at `lg` and above, while smaller viewports use a
compact header with a real hamburger button and an off-canvas right drawer.

Both presentations render the same authorized navigation source from
`resources/views/layouts/partials/navigation-links.blade.php`. Existing role
and Gate checks remain the visibility mechanism; HTTP route authorization
continues to be the security boundary. Guests see New Submission, Log in,
Register, and locale selection. Agents, Officers, and Admins receive their
existing authorized destinations plus New Submission.

The drawer is implemented with vanilla JavaScript in
`resources/js/mobile-navigation.js`. It starts closed, updates
`aria-expanded`, references its controlled drawer with `aria-controls`, closes
through the close button, backdrop, Escape, or normal navigation, locks body
scroll while open, and restores focus to the trigger when closed. The drawer
uses native hidden semantics so its controls are not keyboard-focusable while
closed. Locale presentation remains session-based; locale persistence is still
deferred to Phase 4.

## Phase 2B — Examination Browsing UX

The staff Examination workspace keeps its existing shared Eloquent query and
25-row server pagination for desktop/MacBook browsing. Below the existing `lg`
breakpoint, a small vanilla-JavaScript presentation paginator shows ten rows at
a time and hands off to the server paginator when the current server page is
exhausted. Search, Today filter, and page state are preserved in generated
links.

Staff listing now defaults to `today=1`. Today means the half-open operational
day in `Asia/Kuala_Lumpur`, using `submitted_at` boundaries converted to UTC for
the database query. `today=0` explicitly includes historical records. A
complete canonical `ZB-YYMMDD-NNNN` Submission Number search uses an exact
indexed lookup and bypasses the Today constraint without changing the visible
checkbox state.

On mobile, selecting a staff list item appends `#examination-details` through a
small responsive enhancement; desktop links remain normal links without an
anchor jump. The detail region has the stable `id="examination-details"`.
Empty Today-only results use distinct localized copy. Mobile card redesign,
draft persistence, photo preview restoration, and other later phases remain
deferred.

## Phase 3 — Submission Resilience

The Examination form now keeps non-file in-progress values in a versioned
`sessionStorage` draft. Draft keys are actor-scoped using either `guest` or the
authenticated User's numeric identifier, so switching from one authenticated
User to another in the same browser tab cannot reuse the previous User's draft.
The draft expires after 24 hours and stores only ordinary form fields, including
the ordered Customs Form Number array. It never stores CSRF values, photo
credentials, presigned URLs, files, Blobs, or image bytes.

The form exposes an explicit server-values marker. Laravel old input and
validation state take precedence over a browser draft; otherwise the draft
overrides Agent profile defaults for the current form only. Conditional `Other`
fields and repeatable Customs Form Number rows are restored before the existing
conditional-field synchronization runs. A small translated status is shown for
draft saved/restored/cleared states, and Clear draft returns fields to the
current Agent defaults without touching the independent photo upload session.
The draft is cleared only by explicit Clear draft or the server-gated success
page.

Temporary verified-photo previews are restored without re-uploading images. The
browser resumes the bearer-token-owned `PhotoUploadSession`, then fetches each
verified preview through the protected
`photo-upload-sessions.photos.preview` endpoint and creates a local Blob URL.
The endpoint scopes the photo lookup through the authenticated session, rejects
wrong/cross-session/expired/finalized/pending access, streams private local
objects from the configured Laravel filesystem disk for both proxy and direct
storage. Direct PUT upload remains browser-to-Spaces, but temporary preview GET
is always application-mediated and application-streamed, so browser recovery has
no Spaces GET CORS dependency. Responses are private and no-store; storage
paths and bucket URLs are not exposed. Existing Blob URL replacement/removal
cleanup remains the browser ownership mechanism.

## Phase 4 — Remember Me and Authenticated Locale Preference

Login now exposes a translated `remember` checkbox and passes its boolean value
to Laravel's standard `Auth::attempt($credentials, $remember)` support. The
existing `users.remember_token`, logout invalidation, password-change rotation,
Admin reset rotation, inactive-account enforcement, throttling, session
regeneration, and role-home redirects remain in use. No custom credential,
password, or remember-cookie storage was added.

Locale resolution is centralized in `App\Services\LocaleService`. Guests use
the session locale followed by the application default. Authenticated users use
their supported `users.preferred_locale` followed by the application default;
an authenticated preference therefore overrides any stale guest session locale
after login. The existing `ms`/`en` switch validates the allowlist, updates the
current session, and persists the preference for authenticated users. Guest
locale remains session-only. New registrations initialize `preferred_locale`
from the active locale used during registration.

Phase 3 draft and protected-preview behavior remains unchanged: locale changes
do not clear the actor-scoped draft, temporary photo session, or restored
previews. Agent profile enrichment, soft delete, and broader UI work remain
deferred.

## Phase 5 — Agent Profile Enrichment

After a successful authenticated Agent submission, the existing submission
transaction now calls `AgentProfileEnrichmentService`. The service reacquires
the current User row with `lockForUpdate()` and independently fills only blank
`phone`, `agent_code`, `company_name`, and `station_code` values from the
already normalized submitted snapshot data. It saves only when at least one
field changes.

The Examination historical snapshot is created from submitted values before
enrichment and remains independent of the User profile. Later submissions may
contain different values without overwriting established profile fields.
Guests, Officers, and Admins remain ineligible. Enrichment occurs inside the
existing post-number-allocation persistence transaction, so User changes roll
back with Examination, customs-form, and photo-finalization failures. No
migration was required; the existing User columns are reused.