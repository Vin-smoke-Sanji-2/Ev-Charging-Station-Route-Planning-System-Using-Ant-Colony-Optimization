<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE charging_stations MODIFY COLUMN verification_status ENUM('pending', 'verified', 'rejected', 'suspended') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE charging_stations SET verification_status = 'rejected' WHERE verification_status = 'suspended'");
        DB::statement("ALTER TABLE charging_stations MODIFY COLUMN verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending'");
    }
};
