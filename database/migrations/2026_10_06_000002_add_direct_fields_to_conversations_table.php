<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.1.3 Direct Reporter Messaging.
     *
     * Adds nullable direct-message columns to the existing conversations table.
     * Legacy pair conversations (lost_item_id + found_item_id) are untouched:
     * existing rows keep NULL direct columns and remain governed by the
     * existing unique(['lost_item_id', 'found_item_id']) constraint.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Direct (single-item) conversations store NULL pair columns.
            $table->unsignedBigInteger('lost_item_id')->nullable()->change();
            $table->unsignedBigInteger('found_item_id')->nullable()->change();

            $table->foreignId('direct_item_id')->nullable()->after('found_item_id')
                ->constrained('items')->cascadeOnDelete();
            $table->foreignId('user_one_id')->nullable()->after('direct_item_id')
                ->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->nullable()->after('user_one_id')
                ->constrained('users')->cascadeOnDelete();
            $table->unique(['direct_item_id', 'user_one_id', 'user_two_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['direct_item_id', 'user_one_id', 'user_two_id']);
            $table->dropConstrainedForeignId('user_two_id');
            $table->dropConstrainedForeignId('user_one_id');
            $table->dropConstrainedForeignId('direct_item_id');

            // NOTE: fails if direct conversations with NULL pair columns exist.
            $table->unsignedBigInteger('lost_item_id')->nullable(false)->change();
            $table->unsignedBigInteger('found_item_id')->nullable(false)->change();
        });
    }
};
