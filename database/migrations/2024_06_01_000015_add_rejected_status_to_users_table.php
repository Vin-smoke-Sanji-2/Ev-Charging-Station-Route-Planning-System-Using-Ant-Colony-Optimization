<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('active', 'pending', 'suspended', 'rejected') NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET status = 'suspended' WHERE status = 'rejected'");
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('active', 'pending', 'suspended') NOT NULL DEFAULT 'active'");
    }
};
