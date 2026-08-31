<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['lost_item_id', 'found_item_id'];

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
}
