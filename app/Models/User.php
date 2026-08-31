<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'student_id', 'email', 'role', 'password', 'profile_image'
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $appends = ['profile_image_url', 'reports_count', 'resolved_count', 'role_name'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getRoleNameAttribute()
    {
        return ucfirst($this->role);
    }

    public function getProfileImageUrlAttribute()
    {
        return $this->profile_image ? url(Storage::url($this->profile_image)) : null;
    }

    public function getReportsCountAttribute()
    {
        return $this->items()->count();
    }

    public function getResolvedCountAttribute()
    {
        return $this->items()->where('status', 'RESOLVED')->count();
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
}
