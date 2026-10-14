<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('message_type')->default('text')->after('message');
            $table->foreignId('item_id')->nullable()->after('message_type')->constrained('items')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['item_id']);
            $table->dropColumn(['message_type', 'item_id']);
        });
    }
};
