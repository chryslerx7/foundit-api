<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DirectMessagingTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private function makeUser(string $name, string $role = 'student'): User
    {
        self::$seq++;
        return User::create([
            'name' => $name,
            'student_id' => 'SID-' . self::$seq . '-' . uniqid(),
            'email' => 'user' . self::$seq . '_' . uniqid() . '@example.com',
            'role' => $role,
            'password' => 'password123',
        ]);
    }

    private function makeItem(User $owner, string $type, string $status = 'ACTIVE'): Item
    {
        return Item::create([
            'user_id' => $owner->id,
            'item_name' => 'Black Wallet',
            'category' => 'Wallets',
            'description' => 'Black leather wallet',
            'location' => 'Library 2nd Floor',
            'date' => '2026-10-01',
            'type' => $type,
            'status' => $status,
            'contact' => '09123456789',
        ]);
    }

    public function test_user_can_message_reporter_of_found_item_without_match(): void
    {
        $owner = $this->makeUser('Owner One');
        $viewer = $this->makeUser('Viewer Two');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($viewer);
        $response = $this->postJson("/api/items/{$item->id}/conversation");

        $response->assertStatus(201)->assertJson(['success' => true]);
        $conversationId = $response->json('conversation.id');
        $this->assertNotNull($conversationId);
        $this->assertEquals($item->id, $response->json('conversation.direct_item_id'));

        // Repeat call reuses the same conversation (no duplicates).
        $again = $this->postJson("/api/items/{$item->id}/conversation");
        $again->assertStatus(200);
        $this->assertEquals($conversationId, $again->json('conversation.id'));
        $this->assertEquals(1, Conversation::where('direct_item_id', $item->id)->count());
    }

    public function test_user_can_message_reporter_of_lost_item(): void
    {
        $owner = $this->makeUser('Owner Three');
        $viewer = $this->makeUser('Viewer Four');
        $item = $this->makeItem($owner, 'LOST');

        Sanctum::actingAs($viewer);
        $this->postJson("/api/items/{$item->id}/conversation")->assertSuccessful();
        $this->assertEquals(1, Conversation::where('direct_item_id', $item->id)->count());
    }

    public function test_self_contact_is_rejected(): void
    {
        $owner = $this->makeUser('Owner Five');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($owner);
        $this->postJson("/api/items/{$item->id}/conversation")->assertStatus(422);
        $this->assertEquals(0, Conversation::count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $owner = $this->makeUser('Owner Six');
        $item = $this->makeItem($owner, 'FOUND');

        $this->postJson("/api/items/{$item->id}/conversation")->assertStatus(401);
    }

    public function test_invalid_item_returns_404(): void
    {
        $viewer = $this->makeUser('Viewer Seven');

        Sanctum::actingAs($viewer);
        $this->postJson('/api/items/999999/conversation')->assertStatus(404);
    }

    public function test_visitor_cannot_message_reporter(): void
    {
        $owner = $this->makeUser('Owner Eight');
        $visitor = $this->makeUser('Visitor Nine', 'visitor');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($visitor);
        $this->postJson("/api/items/{$item->id}/conversation")->assertStatus(403);
        $this->assertEquals(0, Conversation::count());
    }

    public function test_direct_conversation_messaging_and_authorization(): void
    {
        $owner = $this->makeUser('Owner Ten');
        $viewer = $this->makeUser('Viewer Eleven');
        $outsider = $this->makeUser('Outsider Twelve');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($viewer);
        $conversationId = $this->postJson("/api/items/{$item->id}/conversation")
            ->assertSuccessful()->json('conversation.id');

        // Viewer sends, owner reads.
        $this->postJson("/api/conversations/{$conversationId}/messages", ['message' => 'Hi! Is this your wallet?'])
            ->assertSuccessful();

        Sanctum::actingAs($owner);
        $this->getJson("/api/conversations/{$conversationId}/messages")
            ->assertSuccessful()->assertJsonCount(1, 'messages');

        // Direct conversation appears in both users' lists.
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(1, 'conversations');
        Sanctum::actingAs($viewer);
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(1, 'conversations');

        // Outsider is rejected from reading and sending.
        Sanctum::actingAs($outsider);
        $this->getJson("/api/conversations/{$conversationId}/messages")->assertStatus(403);
        $this->postJson("/api/conversations/{$conversationId}/messages", ['message' => 'Intruder'])
            ->assertStatus(403);
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(0, 'conversations');
    }

    public function test_legacy_pair_conversation_still_works(): void
    {
        $lostOwner = $this->makeUser('Lost Owner');
        $foundOwner = $this->makeUser('Found Owner');
        $lost = $this->makeItem($lostOwner, 'LOST');
        $found = $this->makeItem($foundOwner, 'FOUND');

        Sanctum::actingAs($lostOwner);
        $response = $this->postJson('/api/conversations', [
            'lost_item_id' => $lost->id,
            'found_item_id' => $found->id,
        ]);
        $response->assertSuccessful()->assertJson(['success' => true]);

        // Reuse, no duplicates.
        $this->postJson('/api/conversations', [
            'lost_item_id' => $lost->id,
            'found_item_id' => $found->id,
        ])->assertSuccessful();
        $this->assertEquals(1, Conversation::count());
    }

    public function test_message_deletion_still_works(): void
    {
        $owner = $this->makeUser('Owner Thirteen');
        $viewer = $this->makeUser('Viewer Fourteen');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($viewer);
        $conversationId = $this->postJson("/api/items/{$item->id}/conversation")
            ->assertSuccessful()->json('conversation.id');
        $messageId = $this->postJson("/api/conversations/{$conversationId}/messages", ['message' => 'Hello'])
            ->assertSuccessful()->json('message.id');

        $this->deleteJson("/api/messages/{$messageId}")->assertSuccessful();

        // Owner cannot delete viewer's message (already deleted -> 404 is also acceptable proof of removal,
        // so recreate and check 403 path explicitly).
        $messageId2 = $this->postJson("/api/conversations/{$conversationId}/messages", ['message' => 'Again'])
            ->assertSuccessful()->json('message.id');
        Sanctum::actingAs($owner);
        $this->deleteJson("/api/messages/{$messageId2}")->assertStatus(403);
    }
}
