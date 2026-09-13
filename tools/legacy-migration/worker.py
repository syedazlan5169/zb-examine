"""Optional local image-processing adapters for the P13B checkpoint worker.

Imports for Pillow, Google API, and boto3 are intentionally deferred to the
operator environment. Constructing these classes never contacts a service.
"""

from __future__ import annotations

import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Protocol

from legacy_migration import MigrationState


class DriveDownloader(Protocol):
    def download(self, file_id: str, destination: Path) -> None: ...


class ObjectStore(Protocol):
    def put(self, storage_path: str, source: Path, mime_type: str) -> None: ...

    def head(self, storage_path: str) -> dict[str, int | str]: ...


@dataclass(frozen=True)
class OptimizedImage:
    path: Path
    mime_type: str
    file_size: int
    width: int
    height: int
    stage: int
    quality: int


class ImageOptimizer:
    MAX_BYTES = 2 * 1024 * 1024
    MAX_DIMENSION = 1600
    BASE_QUALITY = 72

    def optimize(self, source: Path, destination: Path) -> OptimizedImage:
        try:
            from PIL import Image, ImageOps
        except ImportError as exc:
            raise RuntimeError("image_optimizer_dependency_missing: install Pillow locally") from exc

        try:
            with Image.open(source) as image:
                image = ImageOps.exif_transpose(image).convert("RGB")
                original_width, original_height = image.size
                stages = (
                    (self.MAX_DIMENSION, self.BASE_QUALITY),
                    (1400, 65),
                    (1200, 57),
                    (1000, 50),
                )
                selected = None
                for stage, (longest_edge, quality) in enumerate(stages, start=1):
                    candidate = image.copy()
                    scale = min(1.0, longest_edge / max(original_width, original_height))
                    size = (max(1, round(original_width * scale)), max(1, round(original_height * scale)))
                    if size != candidate.size:
                        candidate = candidate.resize(size, Image.Resampling.LANCZOS)
                    candidate.save(destination, format="JPEG", quality=quality, optimize=True)
                    if destination.stat().st_size <= self.MAX_BYTES:
                        selected = (stage, quality, size)
                        break
                    selected = (stage, quality, size)
                if selected is None or destination.stat().st_size > self.MAX_BYTES:
                    raise ValueError("photo_too_large")
                stage, quality, size = selected
                return OptimizedImage(destination, "image/jpeg", destination.stat().st_size, size[0], size[1], stage, quality)
        except ValueError:
            raise
        except Exception as exc:
            raise ValueError("image_decode_failed") from exc


class ImagePreparationWorker:
    def __init__(self, downloader: DriveDownloader, store: ObjectStore, optimizer: ImageOptimizer | None = None, temporary_root: Path | None = None):
        self.downloader = downloader
        self.store = store
        self.optimizer = optimizer or ImageOptimizer()
        self.temporary_root = temporary_root

    def process(self, file_id: str, storage_path: str) -> OptimizedImage:
        with tempfile.TemporaryDirectory(prefix="p13b-", dir=self.temporary_root) as directory:
            original = Path(directory) / "source"
            optimized_path = Path(directory) / "optimized.jpg"
            self.downloader.download(file_id, original)
            optimized = self.optimizer.optimize(original, optimized_path)
            self.store.put(storage_path, optimized.path, optimized.mime_type)
            remote = self.store.head(storage_path)
            if int(remote.get("size", -1)) != optimized.file_size:
                raise RuntimeError("remote_size_mismatch")
            return optimized

    def process_checkpoint(self, state: MigrationState, source_row: int, photo_index: int) -> OptimizedImage | None:
        """Process one eligible checkpoint and persist every durable stage."""
        if not state.source_row_is_planned(source_row):
            raise ValueError("row_not_importable")

        checkpoint = state.image(source_row, photo_index)
        if checkpoint is None:
            raise ValueError("image_checkpoint_missing")
        if checkpoint["download_state"] == "SKIPPED" or checkpoint["verification_state"] == "SKIPPED":
            return None
        if checkpoint["verification_state"] == "COMPLETE":
            return OptimizedImage(
                path=Path(checkpoint["storage_path"]),
                mime_type=checkpoint["mime_type"],
                file_size=checkpoint["file_size"],
                width=checkpoint["width"],
                height=checkpoint["height"],
                stage=0,
                quality=0,
            )

        file_id = checkpoint["drive_file_id"]
        if not file_id:
            state.update_image(source_row, photo_index, download_state="SKIPPED", verification_state="SKIPPED", last_error_code="invalid_drive_reference")
            return None

        attempt_count = int(checkpoint["attempt_count"] or 0) + 1
        state.update_image(source_row, photo_index, attempt_count=attempt_count, download_state="DOWNLOADING")
        try:
            with tempfile.TemporaryDirectory(prefix="p13b-", dir=self.temporary_root) as directory:
                original = Path(directory) / "source"
                optimized_path = Path(directory) / "optimized.jpg"
                self.downloader.download(file_id, original)
                state.update_image(source_row, photo_index, download_state="DOWNLOADED")
                state.update_image(source_row, photo_index, optimization_state="OPTIMIZING")
                optimized = self.optimizer.optimize(original, optimized_path)
                state.update_image(
                    source_row,
                    photo_index,
                    optimization_state="OPTIMIZED",
                    file_size=optimized.file_size,
                    width=optimized.width,
                    height=optimized.height,
                    mime_type=optimized.mime_type,
                )
                state.update_image(source_row, photo_index, upload_state="UPLOADING")
                try:
                    remote = self.store.head(checkpoint["storage_path"])
                except Exception as exception:
                    if "not_found" not in str(exception).lower() and "missing" not in str(exception).lower():
                        raise
                    remote = None
                if remote is None or int(remote.get("size", -1)) != optimized.file_size:
                    self.store.put(checkpoint["storage_path"], optimized.path, optimized.mime_type)
                state.update_image(source_row, photo_index, upload_state="UPLOADED", verification_state="VERIFYING")
                remote = self.store.head(checkpoint["storage_path"])
                if int(remote.get("size", -1)) != optimized.file_size:
                    raise RuntimeError("remote_size_mismatch")
                state.update_image(source_row, photo_index, verification_state="COMPLETE", storage_path=checkpoint["storage_path"])
                return optimized
        except (ValueError, RuntimeError) as exception:
            error_code = str(exception)
            terminal = error_code in {"photo_too_large", "image_decode_failed", "unsupported_image", "invalid_drive_reference"}
            state.update_image(
                source_row,
                photo_index,
                download_state="SKIPPED" if terminal else "RETRYABLE_FAILURE",
                verification_state="SKIPPED" if terminal else "RETRYABLE_FAILURE",
                last_error_code=error_code,
            )
            if terminal:
                return None
            raise