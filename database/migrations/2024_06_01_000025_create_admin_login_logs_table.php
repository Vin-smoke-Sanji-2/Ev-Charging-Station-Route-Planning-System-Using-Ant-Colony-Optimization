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
        // AuthController::verifyOtp() - one row per successful admin login.
        // admin is the only role that ever authenticates through verifyOtp()
        // without also being station_owner, but this table isn't restricted
        // to a specific role at the schema level; the controller only ever
        // writes a row when $user->role === 'admin'. user_id is nullable +
        // nullOnDelete (not cascade) so this stays a real audit trail even
        // if a user-deletion feature is ever added later - no such feature
        // exists in this app today, so this is defensive, not exercised.
        Schema::create('admin_login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('logged_in_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_login_logs');
    }
};
