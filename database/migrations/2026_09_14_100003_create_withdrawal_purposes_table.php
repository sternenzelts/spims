<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purposes differ between companies, so they are rows, not a CHECK constraint (design §3.6).
        Schema::create('withdrawal_purposes', function (Blueprint $table) {
            $table->id();
            $table->text('code')->unique();
            $table->text('name')->unique();
            $table->boolean('requires_remarks')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_purposes');
    }
};
