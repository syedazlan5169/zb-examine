#!/usr/bin/env python3
"""Read-only planning and resumable preparation primitives for P13B.

The default commands only inspect the workbook or local SQLite state. Drive and
Spaces adapters are deliberately optional and are never constructed implicitly.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sqlite3
import sys
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable

try:
    import openpyxl
except ImportError as exc:  # pragma: no cover - exercised by the CLI environment
    raise SystemExit("Install the local dependency with: python3 -m pip install -r requirements.txt") from exc


BUSINESS_TIMEZONE = "Asia/Kuala_Lumpur"
MANIFEST_VERSION = 1
MAX_DAILY_SEQUENCE = 9999
MAX_CUSTOMS_LENGTH = 100
MAX_PHOTOS = 10
IMAGE_COLUMNS = [f"GAMBAR {index}" for index in range(1, MAX_PHOTOS + 1)]
REQUIRED_COLUMNS = [
    "Timestamp",
    "NAMA PENUH",
    "NOMBOR TELEFON",
    "KOD EJEN",
    "NAMA SYARIKAT EJEN",
    "KOD STESYEN",
    "LOKASI",
    "BORANG KASTAM",
    "NOMBOR BORANG KASTAM",
    "STATUS KONTENA",
    "PEGAWAI YANG HADIR PEMERIKSAAN",
]

LOCATION_MAP = {
    "TERMINAL GATE KONTENA": "container_gate_terminal",
    "GATE CONVENTIONAL": "conventional_gate",
}
FORM_TYPE_MAP = {
    "LAMPIRAN A ( TARIK BALIK )": "attachment_a",
    "KASTAM 1 ( K1 )": "k1",
    "KASTAM 2 ( K2 )": "k2",
    "KASTAM 3 ( K3 )": "k3",
    "KASTAM 8 ( K8 )": "k8",
    "ATA CARNET": "ata_carnet",
}
CONTAINER_STATUS_MAP = {value: value.lower() for value in ("FCL", "LCL", "CONVENTIONAL")}
REASON_MAP = {
    "ARAHAN PEGAWAI PENAKSIR": "assessing_officer_instruction",
    "PEMINDAHAN ( K8 )": "transfer_k8",
    "DRAWBACK": "drawback",
    "IMPORT SEMENTARA": "temporary_import",
    "EKSPORT SEMENTARA": "temporary_export",
    "EKSPORT DIBATALKAN": "export_cancelled",
    "PELUPUSAN": "disposal",
    "ATA CARNET": "ata_carnet",
}
OFFICER_MAP = {"KASTAM": "customs", "SWCORPS": "swcorps"}

DRIVE_PATTERNS = (
    re.compile(r"^https?://drive\.google\.com/open\?id=([A-Za-z0-9_-]+)$", re.I),
    re.compile(r"^https?://drive\.google\.com/file/d/([A-Za-z0-9_-]+)(?:/[^?]*)?$", re.I),
)
ULID_ALPHABET = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"


def text(value: Any) -> str:
    return "" if value is None else str(value).strip()


def parse_timestamp(value: Any) -> datetime | None:
    if isinstance(value, datetime):
        parsed = value
    else:
        raw = text(value)
        if not raw:
            return None
        try:
            parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
        except ValueError:
            return None
    if parsed.tzinfo is not None:
        return parsed.astimezone(timezone.utc)
    # Excel timestamps in this workbook are explicitly Malaysia local time.
    from zoneinfo import ZoneInfo

    return parsed.replace(tzinfo=ZoneInfo(BUSINESS_TIMEZONE)).astimezone(timezone.utc)


def iso_utc(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat(timespec="microseconds").replace("+00:00", "Z")


def business_date(value: datetime) -> str:
    from zoneinfo import ZoneInfo

    return value.astimezone(ZoneInfo(BUSINESS_TIMEZONE)).date().isoformat()


def drive_file_id(value: Any) -> str | None:
    raw = text(value)
    for pattern in DRIVE_PATTERNS:
        match = pattern.match(raw)
        if match:
            return match.group(1)
    return None


def deterministic_id(prefix: str, source_row: int, photo_index: int) -> str:
    digest = hashlib.sha256(f"p13b:{prefix}:{source_row}:{photo_index}".encode()).digest()
    value = int.from_bytes(digest[:16], "big")
    chars = []
    for _ in range(26):
        chars.append(ULID_ALPHABET[value & 31])
        value >>= 5
    return "".join(reversed(chars))


def legacy_storage_path(source_row: int, photo_index: int) -> str:
    session_id = deterministic_id("session", source_row, 0)
    photo_id = deterministic_id("photo", source_row, photo_index)
    digest = hashlib.sha256(f"p13b-object:{source_row}:{photo_index}".encode()).hexdigest()[:48]
    return f"photo-uploads/{session_id}/{photo_id}/{digest}.jpg"


def is_finalized_spaces_path(path: str) -> bool:
    return re.fullmatch(
        r"photo-uploads/[0-9A-HJKMNP-TV-Z]{26}/[0-9A-HJKMNP-TV-Z]{26}/[a-f0-9]{48}\.jpg",
        path,
    ) is not None


def map_value(raw: Any, mapping: dict[str, str], *, nullable: bool = False) -> str | None:
    value = text(raw)
    if nullable and value == "":
        return None
    return mapping.get(value)


def normalize_customs(raw: Any) -> tuple[list[str], str | None]:
    value = text(raw)
    if not value:
        return [], "customs_form_empty"
    if len(value) > MAX_CUSTOMS_LENGTH:
        return [], "customs_form_too_long"
    return [value], None


class WorkbookProfiler:
    def __init__(self, workbook_path: Path):
        self.workbook_path = workbook_path

    def rows(self) -> tuple[list[str], Iterable[tuple[int, dict[str, Any]]], list[str]]:
        workbook = openpyxl.load_workbook(self.workbook_path, read_only=True, data_only=True)
        sheet_names = workbook.sheetnames
        sheet = workbook.active
        iterator = sheet.iter_rows(values_only=True)
        headers = [text(value) for value in next(iterator)]

        def generator() -> Iterable[tuple[int, dict[str, Any]]]:
            for source_row, values in enumerate(iterator, start=2):
                yield source_row, {headers[index]: values[index] if index < len(values) else None for index in range(len(headers))}

        return headers, generator(), sheet_names

    def profile(self) -> dict[str, Any]:
        headers, rows, sheets = self.rows()
        enum_columns = ["LOKASI", "BORANG KASTAM", "STATUS KONTENA", "SEBAB", "PEGAWAI YANG HADIR PEMERIKSAAN"]
        enum_values = {column: Counter() for column in enum_columns}
        required_failures = Counter()
        customs_values = Counter()
        malformed_images = []
        timestamps = []
        image_count = valid_image_count = 0
        drive_ids = set()
        response_rows = 0

        for source_row, row in rows:
            response_rows += 1
            for column in enum_columns:
                enum_values[column][text(row.get(column)) or "<EMPTY>"] += 1
            for column in REQUIRED_COLUMNS:
                if not text(row.get(column)):
                    required_failures[column] += 1
            timestamp = parse_timestamp(row.get("Timestamp"))
            if timestamp is not None:
                timestamps.append(timestamp)
            customs_values[text(row.get("NOMBOR BORANG KASTAM")) or "<EMPTY>"] += 1
            for image_column in IMAGE_COLUMNS:
                raw = text(row.get(image_column))
                if not raw:
                    continue
                image_count += 1
                file_id = drive_file_id(raw)
                if file_id is None:
                    malformed_images.append({"source_row": source_row, "image_column": image_column, "raw_value": raw[:200]})
                else:
                    valid_image_count += 1
                    drive_ids.add(file_id)

        return {
            "workbook": str(self.workbook_path),
            "sheets": sheets,
            "headers": headers,
            "response_rows": response_rows,
            "date_min": iso_utc(min(timestamps)) if timestamps else None,
            "date_max": iso_utc(max(timestamps)) if timestamps else None,
            "invalid_timestamps": response_rows - len(timestamps),
            "enum_values": {column: dict(values) for column, values in enum_values.items()},
            "required_failures": dict(required_failures),
            "customs_values": dict(customs_values),
            "images": {
                "populated": image_count,
                "valid_drive_urls": valid_image_count,
                "unique_drive_ids": len(drive_ids),
                "malformed": len(malformed_images),
                "malformed_items": malformed_images,
            },
        }

    def plan(self, state: "MigrationState") -> dict[str, Any]:
        headers, rows, sheets = self.rows()
        planned = []
        skipped = []
        for source_row, row in rows:
            reasons = []
            timestamp = parse_timestamp(row.get("Timestamp"))
            state.upsert_image_references(source_row, row)
            if timestamp is None:
                reasons.append("invalid_timestamp")
            fields = {
                "location": map_value(row.get("LOKASI"), LOCATION_MAP),
                "form_type": map_value(row.get("BORANG KASTAM"), FORM_TYPE_MAP),
                "container_status": map_value(row.get("STATUS KONTENA"), CONTAINER_STATUS_MAP),
                "reason": map_value(row.get("SEBAB"), REASON_MAP, nullable=True),
                "attending_officer_type": map_value(row.get("PEGAWAI YANG HADIR PEMERIKSAAN"), OFFICER_MAP),
            }
            for column in REQUIRED_COLUMNS:
                if not text(row.get(column)):
                    reasons.append(f"required_empty:{column}")
            for field in ("location", "form_type", "container_status", "attending_officer_type"):
                if fields[field] is None:
                    reasons.append(f"unmapped:{field}")
            if text(row.get("SEBAB")) and fields["reason"] is None:
                reasons.append("unmapped:reason")
            customs, customs_error = normalize_customs(row.get("NOMBOR BORANG KASTAM"))
            if customs_error:
                reasons.append(customs_error)
            if reasons:
                skipped.append({"source_row": source_row, "reasons": sorted(set(reasons))})
                state.upsert_examination(source_row, timestamp, None, "SKIPPED", ";".join(sorted(set(reasons))))
                continue
            planned.append({
                "manifest_version": MANIFEST_VERSION,
                "source_row": source_row,
                "timestamp": timestamp,
                "submitted_at_utc": iso_utc(timestamp),
                "agent_name": text(row.get("NAMA PENUH")),
                "agent_phone": text(row.get("NOMBOR TELEFON")),
                "agent_code": text(row.get("KOD EJEN")).upper(),
                "agent_company_name": text(row.get("NAMA SYARIKAT EJEN")),
                "agent_station_code": text(row.get("KOD STESYEN")),
                "location": fields["location"],
                "form_type": fields["form_type"],
                "form_type_other": None,
                "container_status": fields["container_status"],
                "reason": fields["reason"],
                "reason_other": None,
                "attending_officer_type": fields["attending_officer_type"],
                "customs_form_numbers": customs,
                "photos": [
                    {
                        "photo_index": photo_index,
                        "drive_file_id": drive_file_id(row.get(image_column)),
                        "source_value": text(row.get(image_column)),
                        "storage_path": legacy_storage_path(source_row, photo_index),
                    }
                    for photo_index, image_column in enumerate(IMAGE_COLUMNS, start=1)
                    if text(row.get(image_column))
                ],
            })
        planned.sort(key=lambda item: (item["timestamp"], item["source_row"]))
        per_date = Counter()
        for item in planned:
            date = business_date(item["timestamp"])
            per_date[date] += 1
            if per_date[date] > MAX_DAILY_SEQUENCE:
                skipped.append({"source_row": item["source_row"], "reasons": ["daily_sequence_exhausted"]})
                state.upsert_examination(item["source_row"], item["timestamp"], None, "SKIPPED", "daily_sequence_exhausted")
                continue
            item["submission_no"] = f"ZB-{date[2:4]}{date[5:7]}{date[8:10]}-{per_date[date]:04d}"
            state.upsert_examination(item["source_row"], item["timestamp"], item["submission_no"], "PLANNED", None, item)
        importable = [item for item in planned if item.get("submission_no")]
        for item in importable:
            state.upsert_images(item["source_row"], item)
        return {
            "sheets": sheets,
            "headers": headers,
            "valid_planned_rows": len(importable),
            "skipped_rows": skipped,
            "submission_first": importable[0]["submission_no"] if importable else None,
            "submission_last": importable[-1]["submission_no"] if importable else None,
            "dates": dict(per_date),
        }


class MigrationState:
    def __init__(self, database_path: Path):
        self.connection = sqlite3.connect(database_path)
        self.connection.execute("PRAGMA journal_mode=WAL")
        self.connection.executescript("""
            CREATE TABLE IF NOT EXISTS examinations (
                source_row INTEGER PRIMARY KEY, source_timestamp TEXT, validation_state TEXT,
                skip_reason TEXT, submission_no TEXT, submitted_at_utc TEXT, manifest_state TEXT,
                payload_json TEXT
            );
            CREATE TABLE IF NOT EXISTS images (
                source_row INTEGER NOT NULL, photo_index INTEGER NOT NULL, drive_file_id TEXT,
                download_state TEXT NOT NULL DEFAULT 'PENDING', optimization_state TEXT NOT NULL DEFAULT 'PENDING',
                upload_state TEXT NOT NULL DEFAULT 'PENDING', verification_state TEXT NOT NULL DEFAULT 'PENDING',
                storage_path TEXT, mime_type TEXT, file_size INTEGER, width INTEGER, height INTEGER,
                attempt_count INTEGER NOT NULL DEFAULT 0, last_error_code TEXT,
                PRIMARY KEY (source_row, photo_index)
            );
        """)
        columns = {row[1] for row in self.connection.execute("PRAGMA table_info(examinations)")}
        if "payload_json" not in columns:
            self.connection.execute("ALTER TABLE examinations ADD COLUMN payload_json TEXT")
        self.connection.commit()

    def upsert_examination(self, source_row: int, timestamp: datetime | None, submission_no: str | None, state: str, reason: str | None, payload: dict[str, Any] | None = None) -> None:
        self.connection.execute(
            "INSERT INTO examinations(source_row,source_timestamp,validation_state,skip_reason,submission_no,submitted_at_utc,manifest_state,payload_json) VALUES(?,?,?,?,?,?,?,?) "
            "ON CONFLICT(source_row) DO UPDATE SET source_timestamp=excluded.source_timestamp,validation_state=excluded.validation_state,skip_reason=excluded.skip_reason,submission_no=excluded.submission_no,submitted_at_utc=excluded.submitted_at_utc,payload_json=excluded.payload_json",
            (source_row, iso_utc(timestamp) if timestamp else None, state, reason, submission_no, iso_utc(timestamp) if timestamp else None, "PENDING", json.dumps(payload, default=str) if payload else None),
        )
        self.connection.commit()

    def upsert_images(self, source_row: int, item: dict[str, Any]) -> None:
        # Planning records references only; no Drive or Spaces call occurs here.
        for photo in item["photos"]:
            self.connection.execute(
                "INSERT OR IGNORE INTO images(source_row,photo_index,drive_file_id,storage_path) VALUES(?,?,?,?)",
                (source_row, photo["photo_index"], photo["drive_file_id"], photo["storage_path"]),
            )
        self.connection.commit()

    def upsert_image_references(self, source_row: int, row: dict[str, Any]) -> None:
        for photo_index, image_column in enumerate(IMAGE_COLUMNS, start=1):
            raw_value = text(row.get(image_column))
            if not raw_value:
                continue
            file_id = drive_file_id(raw_value)
            self.connection.execute(
                "INSERT OR IGNORE INTO images(source_row,photo_index,drive_file_id,storage_path,download_state,verification_state,last_error_code) VALUES(?,?,?,?,?,?,?)",
                (
                    source_row,
                    photo_index,
                    file_id,
                    legacy_storage_path(source_row, photo_index),
                    "PENDING" if file_id else "SKIPPED",
                    "PENDING" if file_id else "SKIPPED",
                    None if file_id else "invalid_drive_reference",
                ),
            )
        self.connection.commit()

    def image(self, source_row: int, photo_index: int) -> sqlite3.Row | None:
        self.connection.row_factory = sqlite3.Row
        return self.connection.execute(
            "SELECT * FROM images WHERE source_row=? AND photo_index=?",
            (source_row, photo_index),
        ).fetchone()

    def source_row_is_planned(self, source_row: int) -> bool:
        row = self.connection.execute(
            "SELECT 1 FROM examinations WHERE source_row=? AND validation_state='PLANNED'",
            (source_row,),
        ).fetchone()

        return row is not None

    def processable_images(self) -> list[sqlite3.Row]:
        self.connection.row_factory = sqlite3.Row

        return self.connection.execute(
            """
            SELECT images.*
            FROM images
            INNER JOIN examinations ON examinations.source_row = images.source_row
            WHERE examinations.validation_state='PLANNED'
              AND images.download_state NOT IN ('SKIPPED', 'COMPLETE')
            ORDER BY images.source_row, images.photo_index
            """,
        ).fetchall()

    def update_image(self, source_row: int, photo_index: int, **values: Any) -> None:
        allowed = {
            "download_state", "optimization_state", "upload_state", "verification_state",
            "storage_path", "mime_type", "file_size", "width", "height", "attempt_count",
            "last_error_code",
        }
        values = {key: value for key, value in values.items() if key in allowed}
        if not values:
            return
        assignments = ", ".join(f"{key}=?" for key in values)
        self.connection.execute(
            f"UPDATE images SET {assignments} WHERE source_row=? AND photo_index=?",
            (*values.values(), source_row, photo_index),
        )
        self.connection.commit()


def manifest_checksum(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def write_manifest(state: MigrationState, output: Path) -> dict[str, int]:
    """Write every PLANNED row once all of its images reach a terminal state.

    A parent with zero COMPLETE images (all terminal-skipped) still emits a
    manifest record with an empty photos list; only unresolved (non-terminal)
    images defer the row.
    """
    records = []
    for row in state.connection.execute(
        "SELECT source_row, payload_json FROM examinations WHERE validation_state='PLANNED' ORDER BY source_row"
    ):
        if not row[1]:
            continue
        payload = json.loads(row[1])
        images = state.connection.execute(
            """
            SELECT photo_index, storage_path, mime_type, file_size, width, height
            FROM images
            WHERE source_row=? AND verification_state='COMPLETE'
            ORDER BY photo_index
            """,
            (row[0],),
        ).fetchall()
        states = state.connection.execute(
            "SELECT download_state, verification_state FROM images WHERE source_row=? ORDER BY photo_index",
            (row[0],),
        ).fetchall()
        if any(
            download_state not in {"SKIPPED", "COMPLETE"}
            and verification_state not in {"SKIPPED", "COMPLETE"}
            for download_state, verification_state in states
        ):
            continue
        payload["type"] = "examination"
        payload["user_id"] = None
        payload["photos"] = [
            {
                "storage_disk": "photo_uploads_spaces",
                "storage_path": image[1],
                "mime_type": image[2],
                "file_size": image[3],
                "width": image[4],
                "height": image[5],
                "display_order": display_order,
            }
            for display_order, image in enumerate(images, start=1)
        ]
        payload.pop("timestamp", None)
        payload["customs_form_numbers"] = payload.get("customs_form_numbers", [])
        records.append(payload)
    with output.open("w", encoding="utf-8", newline="\n") as stream:
        for record in records:
            stream.write(json.dumps(record, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n")
    checksum = manifest_checksum(output)
    output.with_name(output.name + ".sha256").write_text(f"{checksum}  {output.name}\n", encoding="ascii")
    return {"records": len(records), "checksum": checksum}


def command_profile(args: argparse.Namespace) -> int:
    print(json.dumps(WorkbookProfiler(args.workbook).profile(), ensure_ascii=False, indent=2))
    return 0


def command_plan(args: argparse.Namespace) -> int:
    state = MigrationState(args.state)
    result = WorkbookProfiler(args.workbook).plan(state)
    print(json.dumps(result, ensure_ascii=False, indent=2, default=str))
    return 0


def command_manifest(args: argparse.Namespace) -> int:
    state = MigrationState(args.state)
    result = write_manifest(state, args.output)
    print(json.dumps(result, indent=2))
    return 0


def command_emit_cross_language_fixture(args: argparse.Namespace) -> int:
    workbook = openpyxl.Workbook()
    sheet = workbook.active
    sheet.append([
        "Timestamp", "NAMA PENUH", "NOMBOR TELEFON", "KOD EJEN", "NAMA SYARIKAT EJEN",
        "KOD STESYEN", "LOKASI", "BORANG KASTAM", "NOMBOR BORANG KASTAM", "STATUS KONTENA",
        "SEBAB", "PEGAWAI YANG HADIR PEMERIKSAAN", *IMAGE_COLUMNS,
    ])
    for name, row_number, form_type, image_count in [
        ("A", 2, "KASTAM 1 ( K1 )", 3),
        ("B", 3, "KASTAM 1 ( K1 )", 1),
        ("Invalid C", 4, "UNKNOWN", 1),
    ]:
        values = [
            "2026-01-02 10:05:46.759", name, "0123456789", "bf0764", "Company", "ST-1",
            "TERMINAL GATE KONTENA", form_type, f"FORM-{row_number}", "FCL", "", "KASTAM",
        ]
        values.extend(
            f"https://drive.google.com/open?id=id-{row_number}-{index}"
            if index <= image_count else ""
            for index in range(1, MAX_PHOTOS + 1)
        )
        sheet.append(values)
    workbook.save(args.workbook)

    state = MigrationState(args.state)
    WorkbookProfiler(args.workbook).plan(state)
    for photo_index in (1, 3):
        state.update_image(
            2,
            photo_index,
            download_state="DOWNLOADED",
            optimization_state="OPTIMIZED",
            upload_state="UPLOADED",
            verification_state="COMPLETE",
            storage_path=legacy_storage_path(2, photo_index),
            mime_type="image/jpeg",
            file_size=1000 + photo_index,
            width=1600,
            height=1200,
        )
    state.update_image(2, 2, download_state="SKIPPED", verification_state="SKIPPED", last_error_code="synthetic_terminal_skip")
    state.update_image(
        3,
        1,
        download_state="DOWNLOADED",
        optimization_state="OPTIMIZED",
        upload_state="UPLOADED",
        verification_state="COMPLETE",
        storage_path=legacy_storage_path(3, 1),
        mime_type="image/jpeg",
        file_size=1001,
        width=1600,
        height=1200,
    )
    write_manifest(state, args.manifest)
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    for name, function in (("profile", command_profile), ("plan", command_plan)):
        subparser = subparsers.add_parser(name)
        subparser.add_argument("workbook", type=Path)
        if name == "plan":
            subparser.add_argument("--state", type=Path, required=True)
        subparser.set_defaults(function=function)
    subparser = subparsers.add_parser("manifest")
    subparser.add_argument("--state", type=Path, required=True)
    subparser.add_argument("--output", type=Path, required=True)
    subparser.set_defaults(function=command_manifest)
    subparser = subparsers.add_parser("emit-cross-language-fixture")
    subparser.add_argument("workbook", type=Path)
    subparser.add_argument("state", type=Path)
    subparser.add_argument("manifest", type=Path)
    subparser.set_defaults(function=command_emit_cross_language_fixture)
    return parser


if __name__ == "__main__":
    arguments = build_parser().parse_args()
    sys.exit(arguments.function(arguments))