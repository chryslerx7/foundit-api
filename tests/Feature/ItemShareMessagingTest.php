<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemShareMessagingTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

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
            'item_name' => 'Gold Ring',
            'category' => 'Jewelry',
            'description' => 'Wedding band',
            'location' => 'Admin Building',
            'date' => '2026-10-01',
            'type' => $type,
            'status' => 'ACTIVE',
            'contact' => '09123456789',
        ]);
    }

    public function test_user_can_share_eligible_item_in_conversation(): void
    {
        $owner = $this->makeUser('Owner');
        $sender = $this->makeUser('Sender');
        $item = $this->makeItem($owner, 'FOUND');

        Sanctum::actingAs($sender);
        $convResponse = $this->postJson("/api/items/{$item->id}/conversation");
        $convId = $convResponse->json('conversation.id');

        $response = $this->postJson("/api/conversations/{$convId}/messages", [
            'message_type' => 'item_share',
            'item_id' => $item->id,
            'message' => 'Check this item report',
        ]);

        $response->assertSuccessful()
            ->assertJson([
                'success' => true,
                'message' => [
                    'message_type' => 'item_share',
                    'item_id' => $item->id,
                ]
            ]);
        $this->assertNotNull($response->json('message.item'));
    }

    public function test_unrelated_item_cannot_be_shared(): void
    {
        $owner = $this->makeUser('Owner');
        $sender = $this->makeUser('Sender');
        $otherUser = $this->makeUser('Other');
        $item = $this->makeItem($owner, 'FOUND');
        $unrelatedItem = $this->makeItem($otherUser, 'LOST');

        Sanctum::actingAs($sender);
        $convResponse = $this->postJson("/api/items/{$item->id}/conversation");
        $convId = $convResponse->json('conversation.id');

        $response = $this->postJson("/api/conversations/{$convId}/messages", [
            'message_type' => 'item_share',
            'item_id' => $unrelatedItem->id,
            'message' => 'Trying to share unrelated item',
        ]);

        $response->assertStatus(422);
    }
}
