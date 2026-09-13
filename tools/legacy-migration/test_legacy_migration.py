import json
import re
import tempfile
import unittest
from pathlib import Path

import openpyxl

from legacy_migration import (
    MANIFEST_VERSION,
    MigrationState,
    deterministic_id,
    drive_file_id,
    is_finalized_spaces_path,
    legacy_storage_path,
    write_manifest,
    WorkbookProfiler,
)
from worker import ImagePreparationWorker, OptimizedImage


class LegacyMigrationTests(unittest.TestCase):
    def test_drive_id_extraction_rejects_malformed_values(self):
        self.assertEqual(drive_file_id("https://drive.google.com/open?id=abc_123-XYZ"), "abc_123-XYZ")
        self.assertEqual(drive_file_id("https://drive.google.com/file/d/abc_123-XYZ/view"), "abc_123-XYZ")
        self.assertIsNone(drive_file_id("IMG_20260806.jpg"))

    def test_path_is_stable_and_matches_application_validator(self):
        path = legacy_storage_path(42, 7)
        self.assertEqual(path, legacy_storage_path(42, 7))
        self.assertTrue(is_finalized_spaces_path(path))

    def test_generated_ids_are_valid_ulid_shape(self):
        identifier = deterministic_id("session", 42, 0)
        self.assertEqual(len(identifier), 26)
        self.assertRegex(identifier, re.compile(r"^[0-9A-HJKMNP-TV-Z]{26}$"))

    def test_terminal_image_skip_keeps_parent_manifestable_and_renumbers_photos(self):
        with tempfile.TemporaryDirectory() as directory:
            state = MigrationState(Path(directory) / "state.sqlite")
            payload = {
                "manifest_version": MANIFEST_VERSION,
                "source_row": 2,
                "submission_no": "ZB-260102-0001",
                "submitted_at_utc": "2026-01-02T02:05:46.759000Z",
                "agent_name": "Agent",
                "agent_phone": "0123456789",
                "agent_code": "AGENT",
                "agent_company_name": "Company",
                "agent_station_code": "ST-1",
                "location": "container_gate_terminal",
                "form_type": "k1",
                "form_type_other": None,
                "container_status": "fcl",
                "reason": None,
                "reason_other": None,
                "attending_officer_type": "customs",
                "customs_form_numbers": ["FORM-1"],
                "photos": [],
            }
            state.upsert_examination(2, None, payload["submission_no"], "PLANNED", None, payload)
            for index in (1, 2, 3):
                state.connection.execute(
                    "INSERT INTO images(source_row,photo_index,drive_file_id,storage_path,download_state,verification_state,mime_type,file_size,width,height) VALUES(?,?,?,?,?,?,?,?,?,?)",
                    (2, index, f"id-{index}", legacy_storage_path(2, index), "COMPLETE" if index != 2 else "SKIPPED", "COMPLETE" if index != 2 else "SKIPPED", "image/jpeg" if index != 2 else None, 100 + index if index != 2 else None, 100, 100),
                )
            state.connection.commit()
            output = Path(directory) / "manifest.jsonl"
            result = write_manifest(state, output)
            self.assertEqual(1, result["records"])
            record = json.loads(output.read_text().strip())
            self.assertEqual(MANIFEST_VERSION, record["manifest_version"])
            self.assertEqual([1, 2], [photo["display_order"] for photo in record["photos"]])

    def test_worker_refuses_skipped_rows_before_downloader_call(self):
        class Downloader:
            def download(self, file_id, destination):
                raise AssertionError("skipped row must not download")

        class Store:
            def put(self, storage_path, source, mime_type):
                raise AssertionError("skipped row must not upload")

            def head(self, storage_path):
                raise AssertionError("skipped row must not HEAD")

        with tempfile.TemporaryDirectory() as directory:
            state = MigrationState(Path(directory) / "state.sqlite")
            state.upsert_examination(2, None, None, "SKIPPED", "unmapped:reason")
            state.connection.execute("INSERT INTO images(source_row,photo_index,drive_file_id,storage_path) VALUES(?,?,?,?)", (2, 1, "id", legacy_storage_path(2, 1)))
            state.connection.commit()
            with self.assertRaisesRegex(ValueError, "row_not_importable"):
                ImagePreparationWorker(Downloader(), Store()).process_checkpoint(state, 2, 1)

    def test_decode_failure_retries_with_fresh_download_and_completes(self):
        class Downloader:
            def __init__(self):
                self.calls = 0

            def download(self, file_id, destination):
                self.calls += 1
                destination.write_bytes(b"bad" if self.calls == 1 else b"good")

        class Optimizer:
            def __init__(self):
                self.calls = 0

            def optimize(self, source, destination):
                self.calls += 1
                if self.calls == 1:
                    raise ValueError("image_decode_failed")
                destination.write_bytes(b"jpeg")
                return OptimizedImage(destination, "image/jpeg", 4, 10, 10, 1, 72)

        class Store:
            def __init__(self):
                self.put_calls = 0

            def put(self, storage_path, source, mime_type):
                self.put_calls += 1

            def head(self, storage_path):
                if self.put_calls == 0:
                    raise RuntimeError("not_found")
                return {"size": 4, "etag": "etag"}

        with tempfile.TemporaryDirectory() as directory:
            state = MigrationState(Path(directory) / "state.sqlite")
            state.upsert_examination(2, None, "ZB-260102-0001", "PLANNED", None, {
                "manifest_version": 1,
                "source_row": 2,
                "submission_no": "ZB-260102-0001",
                "submitted_at_utc": "2026-01-02T02:05:46.759000Z",
            })
            state.connection.execute(
                "INSERT INTO images(source_row,photo_index,drive_file_id,storage_path) VALUES(?,?,?,?)",
                (2, 1, "drive-id", legacy_storage_path(2, 1)),
            )
            state.connection.commit()
            downloader = Downloader()
            optimizer = Optimizer()
            store = Store()
            worker = ImagePreparationWorker(downloader, store, optimizer, Path(directory))

            with self.assertRaisesRegex(ValueError, "image_decode_failed"):
                worker.process_checkpoint(state, 2, 1)
            checkpoint = state.image(2, 1)
            self.assertEqual("RETRYABLE_FAILURE", checkpoint["verification_state"])
            self.assertEqual(1, checkpoint["attempt_count"])

            worker.process_checkpoint(state, 2, 1)
            checkpoint = state.image(2, 1)
            self.assertEqual("COMPLETE", checkpoint["verification_state"])
            self.assertEqual(2, checkpoint["attempt_count"])
            self.assertEqual(2, downloader.calls)
            self.assertEqual(2, optimizer.calls)
            self.assertEqual(1, store.put_calls)

    def test_planner_generated_record_writes_versioned_manifest(self):
        with tempfile.TemporaryDirectory() as directory:
            workbook_path = Path(directory) / "fixture.xlsx"
            workbook = openpyxl.Workbook()
            sheet = workbook.active
            sheet.append([
                "Timestamp", "NAMA PENUH", "NOMBOR TELEFON", "KOD EJEN", "NAMA SYARIKAT EJEN",
                "KOD STESYEN", "LOKASI", "BORANG KASTAM", "NOMBOR BORANG KASTAM", "STATUS KONTENA",
                "SEBAB", "PEGAWAI YANG HADIR PEMERIKSAAN", "GAMBAR 1", "GAMBAR 2",
            ])
            sheet.append([
                "2026-01-02 10:05:46.759", "Agent", "0123456789", "bf0764", "Company", "ST-1",
                "TERMINAL GATE KONTENA", "KASTAM 1 ( K1 )", "FORM-1", "FCL", "", "KASTAM",
                "https://drive.google.com/open?id=file-1", "101021971",
            ])
            workbook.save(workbook_path)
            state = MigrationState(Path(directory) / "state.sqlite")
            result = WorkbookProfiler(workbook_path).plan(state)
            self.assertEqual(1, result["valid_planned_rows"])
            state.update_image(2, 1, download_state="DOWNLOADED", verification_state="COMPLETE", storage_path=legacy_storage_path(2, 1), mime_type="image/jpeg", file_size=100, width=100, height=100)
            output = Path(directory) / "manifest.jsonl"
            write_manifest(state, output)
            record = json.loads(output.read_text().strip())
            self.assertEqual(1, record["manifest_version"])
            self.assertEqual([1], [photo["display_order"] for photo in record["photos"]])


if __name__ == "__main__":
    unittest.main()