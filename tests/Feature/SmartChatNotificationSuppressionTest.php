<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmartChatNotificationSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('fcm_oauth_access_token', 'fake-oauth-token', 3600);
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/test/messages/123'], 200),
        ]);
        config([
            'services.fcm.project_id' => 'test-project',
            'services.fcm.client_email' => 'test@test.com',
            'services.fcm.private_key' => "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC3\n-----END PRIVATE KEY-----",
        ]);
    }

    private function makeUser(string $name): User
    {
        self::$seq++;
        return User::create([
            'name' => $name,
            'student_id' => 'SID-' . self::$seq . '-' . uniqid(),
            'email' => 'user' . self::$seq . '_' . uniqid() . '@example.com',
            'role' => 'student',
            'password' => 'password123',
        ]);
    }

    private function makeItem(User $owner, string $type): Item
    {
        return Item::create([
            'user_id' => $owner->id,
            'item_name' => 'Backpack',
            'category' => 'Bags',
            'description' => 'Blue backpack',
            'location' => 'Cafeteria',
            'date' => '2026-10-01',
            'type' => $type,
            'status' => 'ACTIVE',
            'contact' => '09123456789',
        ]);
    }

    public function test_active_matching_conversation_suppresses_matching_device_push(): void
    {
        $owner = $this->makeUser('Owner');
        $sender = $this->makeUser('Sender');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($sender);
        $convId = $this->postJson("/api/items/{$item->id}/conversation")->json('conversation.id');

        $token = 'fcm-token-device-A';
        DeviceToken::create(['user_id' => $owner->id, 'token' => $token]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/conversations/{$convId}/presence", ['device_token' => $token])
            ->assertSuccessful();

        Sanctum::actingAs($sender);
        $response = $this->postJson("/api/conversations/{$convId}/messages", ['message' => 'Hello owner']);
        $response->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    public function test_presence_auto_registers_device_token_and_suppresses_push(): void
    {
        $owner = $this->makeUser('Owner');
        $sender = $this->makeUser('Sender');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($sender);
        $convId = $this->postJson("/api/items/{$item->id}/conversation")->json('conversation.id');

        $token = 'unregistered-token-device-Z';

        Sanctum::actingAs($owner);
        $this->postJson("/api/conversations/{$convId}/presence", ['device_token' => $token])
            ->assertSuccessful();

        $this->assertDatabaseHas('device_tokens', ['user_id' => $owner->id, 'token' => $token]);

        Sanctum::actingAs($sender);
        $this->postJson("/api/conversations/{$convId}/messages", ['message' => 'Hello owner'])
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    public function test_presence_for_different_conversation_does_not_suppress_push(): void
    {
        $owner = $this->makeUser('Owner');
        $sender = $this->makeUser('Sender');
        $item1 = $this->makeItem($owner, 'FOUND');
        $item2 = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($sender);
        $conv1Id = $this->postJson("/api/items/{$item1->id}/conversation")->json('conversation.id');
        $conv2Id = $this->postJson("/api/items/{$item2->id}/conversation")->json('conversation.id');

        $token = 'fcm-token-device-A';
        DeviceToken::create(['user_id' => $owner->id, 'token' => $token]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/conversations/{$conv1Id}/presence", ['device_token' => $token]);

        Sanctum::actingAs($sender);
        $this->postJson("/api/conversations/{$conv2Id}/messages", ['message' => 'Message for conv2'])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    public function test_expired_or_missing_presence_permits_push(): void
    {
        $owner = $this->makeUser('Owner');
        $sender = $this->makeUser('Sender');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($sender);
        $convId = $this->postJson("/api/items/{$item->id}/conversation")->json('conversation.id');

        $token = 'fcm-token-device-A';
        DeviceToken::create(['user_id' => $owner->id, 'token' => $token]);

        Sanctum::actingAs($sender);
        $this->postJson("/api/conversations/{$convId}/messages", ['message' => 'Hello'])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    public function test_one_active_device_does_not_suppress_another_device_push(): void
    {
        $owner = $this->makeUser('Owner');
        $sender = $this->makeUser('Sender');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($sender);
        $convId = $this->postJson("/api/items/{$item->id}/conversation")->json('conversation.id');

        $tokenA = 'fcm-token-device-A';
        $tokenB = 'fcm-token-device-B';
        DeviceToken::create(['user_id' => $owner->id, 'token' => $tokenA]);
        DeviceToken::create(['user_id' => $owner->id, 'token' => $tokenB]);

        Sanctum::actingAs($owner);
        $this->postJson("/api/conversations/{$convId}/presence", ['device_token' => $tokenA]);

        Sanctum::actingAs($sender);
        $this->postJson("/api/conversations/{$convId}/messages", ['message' => 'Hello'])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com'));
    }

    public function test_unauthorized_users_cannot_register_presence(): void
    {
        $owner = $this->makeUser('Owner');
        $sender = $this->makeUser('Sender');
        $outsider = $this->makeUser('Outsider');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($sender);
        $convId = $this->postJson("/api/items/{$item->id}/conversation")->json('conversation.id');

        $token = 'fcm-token-outsider';
        DeviceToken::create(['user_id' => $outsider->id, 'token' => $token]);

        Sanctum::actingAs($outsider);
        $this->postJson("/api/conversations/{$convId}/presence", ['device_token' => $token])
            ->assertStatus(403);
    }
}
