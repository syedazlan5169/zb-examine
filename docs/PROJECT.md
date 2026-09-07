# ZB Examine

## Product Name

**Malay:** Sistem Daftar Pemeriksaan
**English:** Examine Registration System

Internal project/repository name: `zb-examine`.

## Purpose

ZB Examine replaces the existing Google Form workflow used to register customs container examinations.

During an examination, an agent accompanies the customs officer and takes examination photographs. After the examination, the agent submits the examination details and supporting images.

A releasing officer later searches for the examination record and reviews the submitted information and photographs before making the relevant release decision.

## Primary Users

### Guest Agent

A guest agent can submit an examination without creating or logging into an account.

Guest users:

- can access the examination form;
- can upload examination photographs;
- receive a submission number after successful submission;
- do not have submission history;
- do not receive profile auto-population.

### Registered Agent

A registered agent has the same submission capability as a guest, with additional convenience features.

Registered agents may:

- have personal/company information auto-populated;
- view their previous submissions;
- retain preferences such as interface language.

Authentication must never be required merely to submit an examination.

### Releasing Officer

The officer interface is primarily desktop-oriented.

Officers will be able to:

- view recent submissions;
- search by submission number;
- search by Nombor Borang Kastam / SMK number;
- inspect the complete examination details;
- view submitted examination photographs as large inline images.

The intended desktop layout is a split-screen interface with the examination list/search area and the selected examination details visible together.

### Administrator

Administrative functionality will be introduced as required.

## Localization

Backend code, database names, models, services, variables, comments, and internal architecture use English.

Frontend supports:

- Bahasa Melayu (`ms`) — default;
- English (`en`) — optional.

Frontend strings must use Laravel translation keys rather than hard-coded language-specific text.

Example:

```php
__('examination.submit')
```

The system defaults to Bahasa Melayu for new and guest sessions.

## Nombor Borang Kastam

One examination may contain multiple Nombor Borang Kastam.

These values must not be stored as one combined searchable string.

The database relationship is:

```text
Examination
    |
    +-- Customs Form Number
    +-- Customs Form Number
    +-- Customs Form Number
    +-- ...
```

Each complete customs form number is stored as an individual indexed database record.

The frontend may later support shorthand input such as:

```text
B18112068450,51,52,53
```

while the backend stores:

```text
B18112068450
B18112068451
B18112068452
B18112068453
```

The exact frontend parsing and entry UX will be refined separately.

## Submission Number

Every successfully submitted examination receives a unique human-readable submission number.

The final submission-number format has not yet been decided.

The internal database primary key and public submission number must remain separate concepts.

## Examination Images

A submission supports up to approximately 10 examination images, with around 5 images expected for a typical submission.

Modern phone-camera images may be several megabytes each.

The intended upload pipeline is:

```text
Phone photo
    |
    v
Client-side resize/compression
    |
    v
Optimized photographic format
    |
    v
Direct/background upload
    |
    v
Private object storage
```

Target optimized file size is approximately 400–800 KB where image quality permits, with roughly 1 MB treated as a soft target rather than a destructive hard limit.

Evidence readability has higher priority than achieving a specific file size.

PNG is not the preferred format for normal camera photographs. WebP and/or JPEG will be evaluated using real examination photographs.

Images should begin uploading while the agent continues filling the form where practical, reducing perceived final submission time.

## Officer Image Experience

Images are evidence and should be immediately useful.

The officer interface should display large optimized images directly rather than requiring the officer to open thumbnails individually.

The expected desktop presentation is approximately a two-column image grid inside the examination detail area.

Images may still support click-to-zoom/fullscreen functionality as an additional feature.

Lazy loading may be used for images farther down the page without replacing the large-image presentation.

## Security Principle

Guest submission does not imply public examination access.

Anonymous users may submit examinations, but submitted examination data and photographs must remain protected.

Knowledge of a submission number alone must not provide unrestricted public access to examination evidence.