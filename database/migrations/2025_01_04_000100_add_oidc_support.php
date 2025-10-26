<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('oidc_subject')->nullable()->after('email');
            $table->string('oidc_issuer')->nullable()->after('oidc_subject');
            $table->timestamp('last_oidc_login_at')->nullable()->after('remember_token');

            $table->unique(['oidc_subject', 'oidc_issuer']);
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['oidc_subject', 'oidc_issuer']);
            $table->dropColumn(['oidc_subject', 'oidc_issuer', 'last_oidc_login_at']);
        });

        Schema::dropIfExists('api_tokens');
    }
};
