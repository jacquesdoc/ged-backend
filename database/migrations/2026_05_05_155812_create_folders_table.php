<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('color', 7)->default('#2E7D32');
            $table->string('icon', 50)->default('folder');
            $table->boolean('is_shared')->default(false);
            $table->string('path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('folder_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('permission', ['view', 'edit'])->default('view');
            $table->timestamps();
            $table->unique(['folder_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_shares');
        Schema::dropIfExists('folders');
    }
};