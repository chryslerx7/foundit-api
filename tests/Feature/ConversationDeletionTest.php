<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationHide;
use App\Models\Item;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationDeletionTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 100;

    private function makeUser(string $role = 'student'): User
    {
        self::$seq++;
        return User::create([
            'name' => 'Del User ' . self::$seq,
            'student_id' => 'SID-DEL-' . self::$seq . '-' . uniqid(),
            'email' => 'deluser' . self::$seq . '_' . uniqid() . '@example.com',
            'role' => $role,
            'password' => 'password123',
        ]);
    }

    private function makeItem(User $owner, string $type): Item
    {
        return Item::create([
            'user_id' => $owner->id,
            'item_name' => 'Wallet',
            'category' => 'Wallets',
            'description' => 'A wallet',
            'location' => 'Library',
            'date' => '2026-10-01',
            'type' => $type,
            'status' => 'ACTIVE',
            'contact' => '09170000000',
        ]);
    }

    private function directConversation(User $viewer, Item $item): int
    {
        Sanctum::actingAs($viewer);
        return $this->postJson("/api/items/{$item->id}/conversation")
            ->assertSuccessful()->json('conversation.id');
    }

    public function test_participant_can_hide_conversation_from_own_list_only(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser();
        $item = $this->makeItem($owner, 'FOUND');
        $conversationId = $this->directConversation($viewer, $item);

        Sanctum::actingAs($viewer);
        $this->postJson("/api/conversations/{$conversationId}/messages", ['message' => 'Hello'])
            ->assertSuccessful();

        $this->deleteJson("/api/conversations/{$conversationId}")->assertSuccessful();

        // Hidden for the actor...
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(0, 'conversations');
        // ...but preserved for the other participant, with messages intact.
        Sanctum::actingAs($owner);
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(1, 'conversations');
        $this->getJson("/api/conversations/{$conversationId}/messages")
            ->assertSuccessful()->assertJsonCount(1, 'messages');

        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(1, Message::count());
        $this->assertEquals(1, ConversationHide::count());
    }

    public function test_hide_is_idempotent(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser();
        $item = $this->makeItem($owner, 'FOUND');
        $conversationId = $this->directConversation($viewer, $item);

        Sanctum::actingAs($viewer);
        $this->deleteJson("/api/conversations/{$conversationId}")->assertSuccessful();
        $this->deleteJson("/api/conversations/{$conversationId}")->assertSuccessful();
        $this->assertEquals(1, ConversationHide::count());
    }

    public function test_non_participant_cannot_hide_conversation(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser();
        $outsider = $this->makeUser();
        $item = $this->makeItem($owner, 'FOUND');
        $conversationId = $this->directConversation($viewer, $item);

        Sanctum::actingAs($outsider);
        $this->deleteJson("/api/conversations/{$conversationId}")->assertStatus(403);
        $this->assertEquals(0, ConversationHide::count());
    }

    public function test_unauthenticated_delete_is_rejected(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser();
        $item = $this->makeItem($owner, 'FOUND');
        $conversation = Conversation::create([
            'direct_item_id' => $item->id,
            'user_one_id' => min($owner->id, $viewer->id),
            'user_two_id' => max($owner->id, $viewer->id),
        ]);

        $this->deleteJson("/api/conversations/{$conversation->id}")->assertStatus(401);
        $this->assertEquals(0, ConversationHide::count());
    }

    public function test_invalid_conversation_returns_404(): void
    {
        $viewer = $this->makeUser();

        Sanctum::actingAs($viewer);
        $this->deleteJson('/api/conversations/999999')->assertStatus(404);
    }

    public function test_message_reporter_restores_hidden_conversation_without_duplicates(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser();
        $item = $this->makeItem($owner, 'FOUND');
        $conversationId = $this->directConversation($viewer, $item);

        Sanctum::actingAs($viewer);
        $this->deleteJson("/api/conversations/{$conversationId}")->assertSuccessful();
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(0, 'conversations');

        // Message Reporter reopens the SAME conversation (200 reuse, unhidden).
        $reopen = $this->postJson("/api/items/{$item->id}/conversation")->assertStatus(200);
        $this->assertEquals($conversationId, $reopen->json('conversation.id'));
        $this->assertEquals(1, Conversation::count());
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(1, 'conversations');

        // The other participant's view was never affected.
        Sanctum::actingAs($owner);
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(1, 'conversations');
    }

    public function test_legacy_start_restores_hidden_pair_conversation(): void
    {
        $lostOwner = $this->makeUser();
        $foundOwner = $this->makeUser();
        $lost = $this->makeItem($lostOwner, 'LOST');
        $found = $this->makeItem($foundOwner, 'FOUND');

        Sanctum::actingAs($lostOwner);
        $conversationId = $this->postJson('/api/conversations', [
            'lost_item_id' => $lost->id,
            'found_item_id' => $found->id,
        ])->assertSuccessful()->json('conversation.id');

        $this->deleteJson("/api/conversations/{$conversationId}")->assertSuccessful();
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(0, 'conversations');

        $this->postJson('/api/conversations', [
            'lost_item_id' => $lost->id,
            'found_item_id' => $found->id,
        ])->assertSuccessful();
        $this->assertEquals(1, Conversation::count());
        $this->getJson('/api/conversations')->assertSuccessful()->assertJsonCount(1, 'conversations');
    }

    public function test_message_deletion_still_works_after_hide_feature(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser();
        $item = $this->makeItem($owner, 'FOUND');
        $conversationId = $this->directConversation($viewer, $item);

        Sanctum::actingAs($viewer);
        $messageId = $this->postJson("/api/conversations/{$conversationId}/messages", ['message' => 'Hi'])
            ->assertSuccessful()->json('message.id');
        $this->deleteJson("/api/messages/{$messageId}")->assertSuccessful();
        $this->assertEquals(0, Message::count());
    }
}
