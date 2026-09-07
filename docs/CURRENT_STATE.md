# ZB Examine — Current State

Last updated: 2026-09-08

## Project Status

Initial Laravel and Docker development foundation is operational.

The current domain foundation includes the `Examination`, `ExaminationCustomsFormNumber`, and `ExaminationPhoto` models, related enums, and the Nombor Borang Kastam parser.

The examination workflow and user-facing form have not yet been implemented.

The Nombor Borang Kastam parser is now implemented and covered by dedicated unit tests. It normalizes complete numbers, expands the confirmed two-digit shorthand format, rejects malformed or duplicate values, and does not perform persistence.

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

## Next Development Stage

The infrastructure baseline is complete enough to begin application-domain design.

Next major work should start by defining the examination domain and existing-form fields before building the agent submission interface.

Important upcoming areas include:

```text
Examination model
Multiple SMK/customs form numbers
Guest vs authenticated agent submission
Submission-number generation
Agent profile snapshots
Photo records
Mobile agent form
DigitalOcean Spaces integration
Officer search/detail interface
```

Do not start implementing these blindly from assumptions.

The existing Google Form should be mapped and reviewed so the new application preserves required operational fields while improving weak parts of the previous workflow.