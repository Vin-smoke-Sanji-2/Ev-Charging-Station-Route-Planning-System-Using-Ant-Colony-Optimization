<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Raw DB::statement throughout, not Schema::table()->change() - this
        // project has no doctrine/dbal installed, and Laravel's change()
        // needs it (and is unreliable for enums even with it), same reason
        // as 2024_06_01_000015_add_rejected_status_to_users_table.php.
        DB::statement("ALTER TABLE charging_slots ADD COLUMN power_type ENUM('AC', 'DC') NULL AFTER connector_type");

        DB::statement("UPDATE charging_slots SET power_type = 'DC' WHERE connector_type IN ('CCS2', 'CHAdeMO')");
        DB::statement("UPDATE charging_slots SET power_type = 'AC' WHERE connector_type = 'Type2'");

        DB::statement("ALTER TABLE charging_slots MODIFY COLUMN power_type ENUM('AC', 'DC') NOT NULL");
        DB::statement('ALTER TABLE charging_slots MODIFY COLUMN power_kw DECIMAL(6,2) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE charging_slots MODIFY COLUMN power_kw DECIMAL(6,2) NOT NULL');
        DB::statement('ALTER TABLE charging_slots DROP COLUMN power_type');
    }
};
