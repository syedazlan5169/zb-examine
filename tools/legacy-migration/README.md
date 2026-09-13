# P13B Legacy Migration Tool

This directory contains operator-only preparation tooling. It does not run as
part of Laravel and does not connect to production by default.

Use the ignored `.runtime/` directory for future local SQLite state, temporary
files, OAuth client/token files, and Spaces credential environment. No real
credentials are created by this tool.

## Read-only profiling

```sh
python3 -m venv .venv
. .venv/bin/activate
python3 -m pip install -r tools/legacy-migration/requirements.txt
python3 tools/legacy-migration/legacy_migration.py profile ~/Downloads/current-data.xlsx
```

## Deterministic planning

The planner writes only a local SQLite checkpoint. Keep the database outside
Git and never transfer it as a production credential or database connection.

```sh
python3 tools/legacy-migration/legacy_migration.py plan \
  ~/Downloads/current-data.xlsx \
  --state /path/to/p13b-state.sqlite
```

## Manifest production

Manifest rows are emitted after every image belonging to an importable row has
reached a terminal checkpoint state. `COMPLETE` images are included; `SKIPPED`
images remain in reconciliation state but are omitted and remaining photos are
renumbered contiguously. A JSONL checksum is written beside the manifest and is
mandatory for the Laravel importer.

```sh
python3 tools/legacy-migration/legacy_migration.py manifest \
  --state /path/to/p13b-state.sqlite \
  --output /path/to/p13b-manifest.jsonl
```

The initial planning run emits no production-ready photo rows until the worker
has completed local image processing and checkpoint verification. Drive download
and Spaces upload are separate, explicit worker operations. The worker may only
process image checkpoints joined to `PLANNED` examination rows; malformed and
terminally failed images are marked `SKIPPED` without suppressing their parent.

## Authentication and external services

Use official Google Drive OAuth with read-only access and store the client secret
and refresh token outside the repository with restrictive permissions. Use a
temporary restricted Spaces S3 credential limited to the migration object scope.
Do not place either credential, Drive URL, presigned URL, or token in the
manifest. The optional worker requires Pillow, a Google Drive adapter, and an
S3-compatible object-store adapter supplied by the operator; it does not create
credentials automatically.

The worker deletes its temporary source and optimized files after verified HEAD
completion. SQLite remains the durable retry/checkpoint source.

Before downloading, a future authenticated Drive adapter must call the Drive
metadata endpoint (`files.get`) and reject folders or non-downloadable objects
as terminal image skips. This phase does not authenticate to Google Drive.