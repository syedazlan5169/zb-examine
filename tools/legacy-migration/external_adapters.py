"""Authenticated pilot-only Google Drive and Spaces adapters.

Constructors perform no network calls. OAuth and object-store access start only
when the adapter is used by the explicit pilot runner.
"""

from __future__ import annotations

import io
import json
import os
from pathlib import Path
from typing import Any

from worker import DriveDownloader, ObjectStore


class GoogleDriveDownloader(DriveDownloader):
    SCOPES = ["https://www.googleapis.com/auth/drive.readonly"]
    FOLDER_MIME = "application/vnd.google-apps.folder"

    def __init__(self, client_path: Path, token_path: Path):
        self.client_path = client_path
        self.token_path = token_path
        self._service = None

    def _service_client(self):
        if self._service is not None:
            return self._service

        from google.auth.transport.requests import Request
        from google.oauth2.credentials import Credentials
        from google_auth_oauthlib.flow import InstalledAppFlow
        from googleapiclient.discovery import build

        credentials = None
        if self.token_path.is_file():
            credentials = Credentials.from_authorized_user_file(str(self.token_path), self.SCOPES)
        if credentials is None or not credentials.valid:
            if credentials is not None and credentials.expired and credentials.refresh_token:
                credentials.refresh(Request())
            else:
                flow = InstalledAppFlow.from_client_secrets_file(str(self.client_path), self.SCOPES)
                credentials = flow.run_local_server(port=0)
            self.token_path.write_text(credentials.to_json(), encoding="utf-8")
            os.chmod(self.token_path, 0o600)
        self._service = build("drive", "v3", credentials=credentials, cache_discovery=False)
        return self._service

    def metadata(self, file_id: str) -> dict[str, Any]:
        result = self._service_client().files().get(
            fileId=file_id,
            fields="id,name,mimeType,trashed,size,capabilities(canDownload)",
        ).execute()
        if result.get("trashed") or result.get("mimeType") == self.FOLDER_MIME:
            raise ValueError("drive_object_not_downloadable")
        if result.get("capabilities", {}).get("canDownload") is False:
            raise ValueError("drive_object_not_downloadable")
        return result

    def download(self, file_id: str, destination: Path) -> None:
        from googleapiclient.http import MediaIoBaseDownload

        metadata = self.metadata(file_id)
        if metadata.get("mimeType", "").startswith("application/vnd.google-apps"):
            raise ValueError("drive_object_not_downloadable")
        request = self._service_client().files().get_media(fileId=file_id)
        with destination.open("wb") as output:
            downloader = MediaIoBaseDownload(output, request)
            complete = False
            while not complete:
                _, complete = downloader.next_chunk()


class SpacesObjectStore(ObjectStore):
    def __init__(self, endpoint: str, region: str, bucket: str, access_key: str, secret_key: str):
        self.endpoint = endpoint
        self.region = region
        self.bucket = bucket
        self.access_key = access_key
        self.secret_key = secret_key
        self._client = None

    def _s3(self):
        if self._client is None:
            import boto3

            self._client = boto3.client(
                "s3",
                endpoint_url=self.endpoint,
                region_name=self.region,
                aws_access_key_id=self.access_key,
                aws_secret_access_key=self.secret_key,
            )
        return self._client

    def put(self, storage_path: str, source: Path, mime_type: str) -> None:
        self._s3().upload_file(
            str(source),
            self.bucket,
            storage_path,
            ExtraArgs={"ContentType": mime_type, "ACL": "private"},
        )

    def head(self, storage_path: str) -> dict[str, int | str]:
        result = self._s3().head_object(Bucket=self.bucket, Key=storage_path)
        return {"size": int(result["ContentLength"]), "etag": str(result.get("ETag", ""))}


def spaces_from_environment() -> SpacesObjectStore:
    def clean(value: str | None) -> str | None:
        if value is None:
            return None

        return value.strip().strip("'\"‘’“”").strip()

    required = {
        "endpoint": clean(os.environ.get("P13C_SPACES_ENDPOINT")),
        "region": clean(os.environ.get("P13C_SPACES_REGION")),
        "bucket": clean(os.environ.get("P13C_SPACES_BUCKET")),
        "access_key": clean(os.environ.get("P13C_SPACES_ACCESS_KEY")),
        "secret_key": clean(os.environ.get("P13C_SPACES_SECRET_KEY")),
    }
    missing = [key for key, value in required.items() if not value]
    if missing:
        raise RuntimeError("missing_spaces_runtime_configuration:" + ",".join(missing))
    return SpacesObjectStore(**required)
