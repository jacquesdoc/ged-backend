<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('color', 7)->default('#607D8B');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('document_tags', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['document_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_tags');
        Schema::dropIfExists('tags');
    }
};