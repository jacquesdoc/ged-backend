<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
            $table->string('department')->nullable()->after('avatar');
            $table->string('position')->nullable()->after('department');
            $table->timestamp('last_login_at')->nullable()->after('position');
            $table->unsignedBigInteger('storage_used')->default(0)->after('last_login_at');
            $table->unsignedBigInteger('storage_quota')->default(1073741824)->after('storage_used');
            $table->boolean('email_notifications')->default(true)->after('storage_quota');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar', 'department', 'position',
                'last_login_at', 'storage_used',
                'storage_quota', 'email_notifications',
            ]);
        });
    }
};