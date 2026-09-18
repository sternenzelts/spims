<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People who receive parts. They never sign in (design §3.5).
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->text('employee_no')->unique();
            $table->text('full_name');
            $table->text('department')->nullable();
            $table->text('badge_uid_hash')->nullable()->unique();
            $table->text('badge_uid_encrypted')->nullable();
            $table->text('badge_uid_masked')->nullable();
            $table->text('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};
