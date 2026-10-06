<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['lost_item_id', 'found_item_id', 'direct_item_id', 'user_one_id', 'user_two_id'];

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function lostItem()
    {
        return $this->belongsTo(Item::class, 'lost_item_id');
    }

    public function foundItem()
    {
        return $this->belongsTo(Item::class, 'found_item_id');
    }

    public function directItem()
    {
        return $this->belongsTo(Item::class, 'direct_item_id');
    }

    public function userOne()
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo()
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function isDirect(): bool
    {
        return $this->direct_item_id !== null;
    }

    public function hides()
    {
        return $this->hasMany(ConversationHide::class);
    }

    public function isHiddenFor(int $userId): bool
    {
        return $this->hides()->where('user_id', $userId)->exists();
    }
}
