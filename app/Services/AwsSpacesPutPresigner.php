<?php

namespace App\Services;

use App\Data\DirectPhotoUploadAuthorization;
use Carbon\CarbonInterface;

final class AwsSpacesPutPresigner implements SpacesPutPresigner
{
    public function __construct(private readonly SpacesS3ClientFactory $clients) {}

    public function presign(string $storagePath, CarbonInterface $expiresAt): DirectPhotoUploadAuthorization
    {
        $client = $this->clients->make();
        $command = $client->getCommand('PutObject', [
            'Bucket' => $this->clients->bucket(),
            'Key' => $storagePath,
            'ContentType' => 'image/jpeg',
        ]);

        $request = $client->createPresignedRequest($command, $expiresAt->toDateTimeImmutable());

        return new DirectPhotoUploadAuthorization(
            method: 'PUT',
            url: (string) $request->getUri(),
            requiredHeaders: ['Content-Type' => 'image/jpeg'],
            expiresAt: $expiresAt,
        );
    }
}
