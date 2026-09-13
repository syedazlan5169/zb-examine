#!/usr/bin/env python3
"""Run a bounded P13C pilot using the audited P13B planner/checkpoint flow."""

from __future__ import annotations

import argparse
import json
import shutil
import sqlite3
from pathlib import Path

from external_adapters import GoogleDriveDownloader, spaces_from_environment
from legacy_migration import MigrationState, WorkbookProfiler, write_manifest
from worker import ImagePreparationWorker


def clone_selected_state(source: MigrationState, destination: MigrationState, source_rows: list[int]) -> None:
    source.connection.row_factory = sqlite3.Row
    destination.connection.row_factory = sqlite3.Row
    for source_row in source_rows:
        examination = source.connection.execute(
            "SELECT * FROM examinations WHERE source_row=? AND validation_state='PLANNED'",
            (source_row,),
        ).fetchone()
        if examination is None:
            raise ValueError(f"pilot_row_not_planned:{source_row}")
        columns = [item[1] for item in source.connection.execute("PRAGMA table_info(examinations)")]
        placeholders = ",".join("?" for _ in columns)
        destination.connection.execute(
            f"INSERT INTO examinations({','.join(columns)}) VALUES({placeholders})",
            tuple(examination[column] for column in columns),
        )
        images = source.connection.execute("SELECT * FROM images WHERE source_row=?", (source_row,)).fetchall()
        image_columns = [item[1] for item in source.connection.execute("PRAGMA table_info(images)")]
        placeholders = ",".join("?" for _ in image_columns)
        for image in images:
            destination.connection.execute(
                f"INSERT INTO images({','.join(image_columns)}) VALUES({placeholders})",
                tuple(image[column] for column in image_columns),
            )
    destination.connection.commit()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-state", type=Path, required=True)
    parser.add_argument("--pilot-state", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--google-client", type=Path, required=True)
    parser.add_argument("--google-token", type=Path, required=True)
    parser.add_argument("--source-row", type=int, action="append", required=True)
    parser.add_argument("--temporary-root", type=Path, default=None)
    args = parser.parse_args()

    if not args.google_client.is_file():
        raise SystemExit("google_client_file_missing")
    args.pilot_state.unlink(missing_ok=True)
    source = MigrationState(args.source_state)
    pilot = MigrationState(args.pilot_state)
    clone_selected_state(source, pilot, args.source_row)

    worker = ImagePreparationWorker(
        GoogleDriveDownloader(args.google_client, args.google_token),
        spaces_from_environment(),
        temporary_root=args.temporary_root,
    )
    for image in pilot.processable_images():
        worker.process_checkpoint(pilot, image["source_row"], image["photo_index"])

    result = write_manifest(pilot, args.manifest)
    print(json.dumps({
        "source_rows": args.source_row,
        "manifest": str(args.manifest),
        "records": result["records"],
        "checksum": result["checksum"],
        "photos": sum(1 for _ in pilot.connection.execute("SELECT 1 FROM images WHERE verification_state='COMPLETE'")),
        "skipped_images": sum(1 for _ in pilot.connection.execute("SELECT 1 FROM images WHERE verification_state='SKIPPED'")),
    }, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
