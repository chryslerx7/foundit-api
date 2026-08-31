<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Item;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email'=>'demo@foundit.test'],
            [
                'name'=>'Demo Student',
                'student_id'=>'2024-12345',
                'password'=>Hash::make('password123')
            ]
        );

        Item::firstOrCreate(
            ['item_name'=>'Black Leather Wallet','user_id'=>$user->id],
            [
                'category'=>'Personal Item',
                'description'=>'Black leather wallet with a small silver logo.',
                'location'=>'Library 2nd Floor',
                'date'=>now()->toDateString(),
                'type'=>'LOST',
                'status'=>'ACTIVE',
                'contact'=>$user->email
            ]
        );

        Item::firstOrCreate(
            ['item_name'=>'Blue Umbrella','user_id'=>$user->id],
            [
                'category'=>'Other',
                'description'=>'Blue folding umbrella.',
                'location'=>'Building B',
                'date'=>now()->toDateString(),
                'type'=>'FOUND',
                'status'=>'ACTIVE',
                'contact'=>$user->email
            ]
        );
    }
}
