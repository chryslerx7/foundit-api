<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_name',150);
            $table->string('category',80);
            $table->text('description')->nullable();
            $table->string('location',200);
            $table->date('date');
            $table->enum('type',['LOST','FOUND']);
            $table->enum('status',['ACTIVE','RESOLVED'])->default('ACTIVE');
            $table->string('contact',200)->nullable();
            $table->string('image')->nullable();
            $table->timestamps();

            $table->index(['type','status']);
            $table->index(['category','status']);
            $table->index('date');
        });
    }

    public function down(): void { Schema::dropIfExists('items'); }
};
