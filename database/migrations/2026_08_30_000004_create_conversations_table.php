<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lost_item_id')->constrained('items')->onDelete('cascade');
            $table->foreignId('found_item_id')->constrained('items')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['lost_item_id', 'found_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
