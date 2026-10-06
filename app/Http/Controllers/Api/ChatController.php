<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationHide;
use App\Models\Message;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $conversations = Conversation::where(function ($q) use ($userId) {
            $q->where(function ($qq) use ($userId) {
                $qq->whereHas('lostItem', function($qqq) use ($userId) {
                    $qqq->where('user_id', $userId);
                })->orWhereHas('foundItem', function($qqq) use ($userId) {
                    $qqq->where('user_id', $userId);
                });
            })->orWhere(function ($qq) use ($userId) {
                $qq->whereNotNull('direct_item_id')
                  ->where(function ($qqq) use ($userId) {
                      $qqq->where('user_one_id', $userId)->orWhere('user_two_id', $userId);
                  });
            });
        })->whereDoesntHave('hides', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })->with(['lostItem.user', 'foundItem.user', 'directItem.user', 'userOne:id,name', 'userTwo:id,name', 'messages' => function($q) {
            $q->latest()->limit(1);
        }])->get();

        return response()->json([
            'success' => true,
            'conversations' => $conversations
        ]);
    }

    public function start(Request $request)
    {
        $request->validate([
            'lost_item_id' => 'required|exists:items,id',
            'found_item_id' => 'required|exists:items,id',
        ]);

        $lostItem = Item::findOrFail($request->lost_item_id);
        $foundItem = Item::findOrFail($request->found_item_id);

        if ($lostItem->type !== 'LOST' || $foundItem->type !== 'FOUND') {
            return response()->json(['success' => false, 'message' => 'Invalid item types.'], 400);
        }

        $userId = $request->user()->id;
        if ($lostItem->user_id !== $userId && $foundItem->user_id !== $userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $conversation = Conversation::firstOrCreate([
            'lost_item_id' => $lostItem->id,
            'found_item_id' => $foundItem->id,
        ]);

        // Reopening restores the conversation for the requesting user.
        ConversationHide::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)->delete();

        return response()->json([
            'success' => true,
            'conversation' => $conversation->load(['lostItem.user', 'foundItem.user'])
        ]);
    }

    public function getMessages(Conversation $conversation)
    {
        $userId = Auth::id();
        if (!$this->isParticipant($conversation, $userId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'success' => true,
            'messages' => $conversation->messages()->with('sender:id,name')->get()
        ]);
    }

    public function sendMessage(Request $request, Conversation $conversation)
    {
        $userId = Auth::id();
        if (!$this->isParticipant($conversation, $userId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        $request->validate([
            'message' => 'required|string|max:5000',
        ]);

        $message = $conversation->messages()->create([
            'sender_id' => $userId,
            'message' => $request->message,
        ]);

        return response()->json([
            'success' => true,
            'message' => $message->load('sender:id,name')
        ]);
    }

    public function destroyMessage(Request $request, Message $message)
    {
        $userId = Auth::id();
        abort_unless($message->sender_id === $userId, 403, 'Unauthorized message deletion.');

        $message->delete();

        return response()->json([
            'success' => true,
            'message' => 'Message deleted.'
        ]);
    }

    /**
     * v1.1.3 Direct Reporter Messaging.
     *
     * Opens (or reuses) a conversation between the authenticated user and the
     * owner of the given item. No Possible Match is required. The conversation
     * is anchored to the item and canonicalized by ordered user ids so repeat
     * calls reuse the same conversation instead of creating duplicates.
     */
    public function startFromItem(Request $request, Item $item)
    {
        abort_if($request->user()->role === 'visitor', 403, 'Visitors cannot message reporters.');

        $userId = $request->user()->id;

        abort_if($item->user_id === $userId, 422, 'You cannot message yourself.');
        abort_unless($item->user()->exists(), 404, 'Report owner not found.');

        [$one, $two] = $userId < $item->user_id
            ? [$userId, $item->user_id]
            : [$item->user_id, $userId];

        $conversation = Conversation::firstOrCreate([
            'direct_item_id' => $item->id,
            'user_one_id' => $one,
            'user_two_id' => $two,
        ]);

        // Reopening via Message Reporter restores the conversation for the
        // requesting user instead of creating a duplicate.
        ConversationHide::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)->delete();

        return response()->json([
            'success' => true,
            'conversation' => $conversation->load(['lostItem.user', 'foundItem.user', 'directItem.user', 'userOne:id,name', 'userTwo:id,name'])
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * v1.1.3 Delete Conversation (per-user hide).
     *
     * Removes the conversation from the authenticated user's Messages list
     * only. The conversation, its messages, and the other participant's copy
     * are preserved. Idempotent: hiding an already-hidden conversation
     * succeeds without side effects.
     */
    public function destroyConversation(Request $request, Conversation $conversation)
    {
        $userId = $request->user()->id;
        if (!$this->isParticipant($conversation, $userId)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
        }

        ConversationHide::firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Conversation deleted.'
        ]);
    }

    /**
     * A user participates in a legacy pair conversation by owning one of the
     * paired items, or in a direct conversation by being one of its users.
     */
    private function isParticipant(Conversation $conversation, int $userId): bool
    {
        if ($conversation->direct_item_id !== null) {
            return $userId === (int) $conversation->user_one_id
                || $userId === (int) $conversation->user_two_id;
        }

        return optional($conversation->lostItem)->user_id === $userId
            || optional($conversation->foundItem)->user_id === $userId;
    }
}
