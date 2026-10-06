<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('item_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['item_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_images');
    }
};
