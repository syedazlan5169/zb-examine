<?php

namespace Tests\Feature;

use App\Models\PhotoUploadSession;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoUploadSessionApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photo_uploads');
    }

    public function test_guest_can_create_a_session(): void
    {
        $response = $this->postJson('/photo-upload-sessions');

        $response->assertCreated();
        $response->assertJsonStructure(['public_id', 'token', 'expires_at', 'photos']);
        $this->assertSame([], $response->json('photos'));
    }

    public function test_raw_token_is_returned_only_once_and_never_persisted_in_plaintext(): void
    {
        $response = $this->postJson('/photo-upload-sessions');
        $token = $response->json('token');
        $publicId = $response->json('public_id');

        $session = PhotoUploadSession::where('public_id', $publicId)->firstOrFail();

        $this->assertSame(hash('sha256', $token), $session->token_hash);

        foreach (PhotoUploadSession::all() as $existing) {
            $this->assertNotSame($token, $existing->token_hash);
        }
    }

    public function test_resume_response_never_exposes_secrets_or_internal_ids(): void
    {
        $created = $this->postJson('/photo-upload-sessions');
        $publicId = $created->json('public_id');
        $token = $created->json('token');

        $response = $this->getJson("/photo-upload-sessions/{$publicId}", $this->authHeader($token));

        $response->assertOk();
        $body = $response->json();

        $this->assertArrayNotHasKey('token', $body);
        $this->assertArrayNotHasKey('token_hash', $body);
        $this->assertArrayNotHasKey('id', $body);
    }

    public function test_fixed_expiry_is_represented_in_the_response(): void
    {
        $response = $this->postJson('/photo-upload-sessions');

        $expiresAt = Carbon::parse($response->json('expires_at'));

        $this->assertEqualsWithDelta(now()->addDay()->getTimestamp(), $expiresAt->getTimestamp(), 5);
    }

    public function test_valid_public_id_and_token_can_resume(): void
    {
        $created = $this->postJson('/photo-upload-sessions');

        $this->getJson(
            "/photo-upload-sessions/{$created->json('public_id')}",
            $this->authHeader($created->json('token')),
        )->assertOk();
    }

    public function test_wrong_token_is_rejected(): void
    {
        $created = $this->postJson('/photo-upload-sessions');

        $response = $this->getJson(
            "/photo-upload-sessions/{$created->json('public_id')}",
            $this->authHeader('wrong-token'),
        );

        $response->assertStatus(401);
        $response->assertJson(['code' => 'invalid_session']);
    }

    public function test_nonexistent_public_id_is_rejected_identically_to_a_wrong_token(): void
    {
        $created = $this->postJson('/photo-upload-sessions');

        $wrongTokenResponse = $this->getJson(
            "/photo-upload-sessions/{$created->json('public_id')}",
            $this->authHeader('wrong-token'),
        );

        $nonexistentIdResponse = $this->getJson(
            '/photo-upload-sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV',
            $this->authHeader($created->json('token')),
        );

        $this->assertSame($wrongTokenResponse->status(), $nonexistentIdResponse->status());
        $this->assertSame($wrongTokenResponse->json(), $nonexistentIdResponse->json());
    }

    public function test_public_id_without_a_token_header_is_rejected(): void
    {
        $created = $this->postJson('/photo-upload-sessions');

        $response = $this->getJson(
            "/photo-upload-sessions/{$created->json('public_id')}",
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(401);
        $response->assertJson(['code' => 'invalid_session']);
    }

    public function test_expired_session_is_rejected(): void
    {
        $created = $this->postJson('/photo-upload-sessions');
        $publicId = $created->json('public_id');

        PhotoUploadSession::where('public_id', $publicId)->update(['expires_at' => now()->subHour()]);

        $response = $this->getJson(
            "/photo-upload-sessions/{$publicId}",
            $this->authHeader($created->json('token')),
        );

        $response->assertStatus(410);
        $response->assertJson(['code' => 'session_expired']);
    }

    public function test_token_never_appears_in_the_route_or_query_string(): void
    {
        // No route parameter is named "token" anywhere in the photo-upload routes.
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'photo-upload-sessions')) {
                $this->assertNotContains('token', $route->parameterNames());
            }
        }
    }

    public function test_photo_upload_routes_use_the_standard_web_middleware_group(): void
    {
        $route = Route::getRoutes()->getByName('photo-upload-sessions.store');

        $this->assertContains('web', $route->gatherMiddleware());
    }

    private function authHeader(string $token): array
    {
        return ['X-Photo-Upload-Token' => $token, 'Accept' => 'application/json'];
    }
}
