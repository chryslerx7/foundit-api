<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.1.3 Delete Conversation (per-user hide).
     *
     * A row here means the given user hid the conversation from their own
     * Messages list. The conversation and its messages are preserved for the
     * other participant. Additive only; no existing data is touched.
     */
    public function up(): void
    {
        Schema::create('conversation_hides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_hides');
    }
};
