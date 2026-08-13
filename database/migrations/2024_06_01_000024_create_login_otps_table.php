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
        // AuthController::login()/verifyOtp() - a fresh row per OTP send
        // (not one column on users) so a resend or a second login attempt
        // never has to guess which code is "current"; the newest
        // unconsumed, unexpired row for the user is always the one that
        // counts. code is hashed at rest (like passwords), even though
        // it's short-lived and single-use - cheap defense in depth.
        Schema::create('login_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_otps');
    }
};
