<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keyed by prefix, not type: renaming a prefix and later reusing an old one can never
        // produce a duplicate number.
        Schema::create('transaction_number_sequences', function (Blueprint $table) {
            $table->text('prefix');
            $table->integer('year');
            $table->bigInteger('last_value');
            $table->primary(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_number_sequences');
    }
};
