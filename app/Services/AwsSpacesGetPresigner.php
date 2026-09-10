<?php

namespace App\Services;

use App\Exceptions\SpacesGetPresigningException;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Exception\InvalidRegionException;
use Aws\Exception\UnresolvedEndpointException;
use Aws\Exception\UnresolvedSignatureException;

final class AwsSpacesGetPresigner implements SpacesGetPresigner
{
    public function __construct(private readonly SpacesS3ClientFactory $clients) {}

    public function presign(string $objectKey, int $ttlSeconds, string $filename): string
    {
        try {
            $client = $this->clients->make();
            $command = $client->getCommand('GetObject', [
                'Bucket' => $this->clients->bucket(),
                'Key' => $objectKey,
                'ResponseContentType' => 'image/jpeg',
                'ResponseContentDisposition' => 'inline; filename="'.$filename.'"',
                'ResponseCacheControl' => 'private, no-store, max-age=0',
            ]);

            return (string) $client->createPresignedRequest($command, "+{$ttlSeconds} seconds")->getUri();
        } catch (AwsException|CredentialsException|InvalidRegionException|UnresolvedEndpointException|UnresolvedSignatureException|\InvalidArgumentException $exception) {
            throw new SpacesGetPresigningException(previous: $exception);
        }
    }
}
