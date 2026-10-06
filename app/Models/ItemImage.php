<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ItemImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'item_id',
        'path',
        'position',
    ];

    protected $appends = ['image_url'];

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function getImageUrlAttribute()
    {
        return $this->path ? url(Storage::url($this->path)) : null;
    }
}
