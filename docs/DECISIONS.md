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