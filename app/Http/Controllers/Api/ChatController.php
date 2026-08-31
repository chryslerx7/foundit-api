<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $conversations = Conversation::whereHas('lostItem', function($q) use ($userId) {
            $q->where('user_id', $userId);
        })->orWhereHas('foundItem', function($q) use ($userId) {
            $q->where('user_id', $userId);
        })->with(['lostItem.user', 'foundItem.user', 'messages' => function($q) {
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

        return response()->json([
            'success' => true,
            'conversation' => $conversation->load(['lostItem.user', 'foundItem.user'])
        ]);
    }

    public function getMessages(Conversation $conversation)
    {
        $userId = Auth::id();
        if ($conversation->lostItem->user_id !== $userId && $conversation->foundItem->user_id !== $userId) {
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
        if ($conversation->lostItem->user_id !== $userId && $conversation->foundItem->user_id !== $userId) {
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
}
