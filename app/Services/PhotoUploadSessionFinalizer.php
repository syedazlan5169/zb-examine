<?php

namespace App\Services;

use App\Data\PhotoUploadSessionCredentials;
use App\Exceptions\PhotoUploadInvalid;
use App\Models\Examination;
use App\Models\PhotoUpload;
use App\Models\PhotoUploadSession;
use Illuminate\Support\Collection;

/**
 * The only place Examination photo finalization happens. Composes
 * PhotoUploadSessionResolver rather than reimplementing token auth.
 */
final class PhotoUploadSessionFinalizer
{
    private const MIN_PHOTOS = 1;

    private const MAX_PHOTOS = 10;

    public function __construct(
        private readonly PhotoUploadSessionResolver $resolver,
    ) {}

    /**
     * Fast, unlocked optimization only — never authoritative. No storage/network
     * calls. Nothing loaded here may be reused by lock()/attachPhotos().
     */
    public function precheck(PhotoUploadSessionCredentials $credentials): void
    {
        $session = $this->resolver->resolve($credentials->publicId, $credentials->token);

        $this->resolver->assertNotFinalized($session);

        $photos = $session->photoUploads()->get();

        $this->assertPhotoCount($photos);
        $this->assertAllVerified($photos);
    }

    /**
     * Must run inside an active DB::transaction(). Locks the parent session row,
     * then re-validates everything authoritatively against a fresh child query
     * issued only after the lock is held — never the precheck's loaded rows.
     */
    public function lock(PhotoUploadSessionCredentials $credentials): PhotoUploadSession
    {
        $session = $this->resolver->resolveLocked($credentials->publicId, $credentials->token);

        $photos = $session->photoUploads()
            ->getQuery()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $this->assertPhotoCount($photos);
        $this->assertAllVerified($photos);

        $session->setRelation('photoUploads', $photos);

        return $session;
    }

    /**
     * Metadata-only copy from the locked session's fresh photoUploads (set by
     * lock()) into examination_photos. No storage/network call. display_order
     * is renumbered 1..N contiguously — never copied verbatim from photo_uploads,
     * whose display_order may have gaps after removals.
     */
    public function attachPhotos(Examination $examination, PhotoUploadSession $lockedSession): void
    {
        $rows = $lockedSession->photoUploads
            ->values()
            ->map(fn (PhotoUpload $upload, int $index): array => [
                'storage_disk' => $upload->storage_disk,
                'storage_path' => $upload->storage_path,
                'mime_type' => $upload->mime_type,
                'file_size' => $upload->file_size,
                'width' => $upload->width,
                'height' => $upload->height,
                'display_order' => $index + 1,
            ])
            ->all();

        $examination->photos()->createMany($rows);

        $lockedSession->examination_id = $examination->id;
        $lockedSession->save();
    }

    /**
     * @param  Collection<int, PhotoUpload>  $photos
     */
    private function assertPhotoCount(Collection $photos): void
    {
        if ($photos->count() < self::MIN_PHOTOS || $photos->count() > self::MAX_PHOTOS) {
            throw new PhotoUploadInvalid('photo_count_invalid');
        }
    }

    /**
     * @param  Collection<int, PhotoUpload>  $photos
     */
    private function assertAllVerified(Collection $photos): void
    {
        if ($photos->contains(fn (PhotoUpload $upload): bool => $upload->verified_at === null)) {
            throw new PhotoUploadInvalid('unverified_photo_pending');
        }
    }
}
