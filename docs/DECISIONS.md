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

The customs form parser must reject duplicate normalized numbers rather than silently de-duplicating them. This includes duplicates introduced by shorthand expansion, and parsing remains atomic when any token fails.

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
