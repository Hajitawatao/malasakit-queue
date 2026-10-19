<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3 services only: MSS-DOH, DSWD, PhilHealth.
 * PAGCOR is deactivated, not deleted, so today's and past tickets stay in the records.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('services')->where('code', 'DOH')->update([
            'name' => 'MSS-DOH',
            'spoken_name' => 'M S S, D O H',
            'sort_order' => 0,
        ]);
        DB::table('services')->where('code', 'DSWD')->update(['sort_order' => 1]);
        DB::table('services')->where('code', 'PHIC')->update(['sort_order' => 2]);

        $pagcor = DB::table('services')->where('code', 'PAGCOR')->value('id');
        if ($pagcor) {
            DB::table('services')->where('id', $pagcor)->update(['is_active' => false]);
            $counters = DB::table('counters')->where('service_id', $pagcor)->pluck('id');
            DB::table('counters')->whereIn('id', $counters)->update(['is_active' => false]);
            // Staff accounts of PAGCOR windows can no longer call anyone
            DB::table('users')->whereIn('counter_id', $counters)->update(['counter_id' => null]);
        }

        // Window names follow the new service name
        DB::table('counters')
            ->whereIn('service_id', DB::table('services')->where('code', 'DOH')->pluck('id'))
            ->where('name', 'like', 'MSS / DOH-MAIP%')
            ->update(['name' => DB::raw("REPLACE(name, 'MSS / DOH-MAIP', 'MSS-DOH')")]);
    }

    public function down(): void
    {
        DB::table('services')->where('code', 'DOH')->update(['name' => 'MSS / DOH-MAIP', 'spoken_name' => 'M S S, D O H MAIP']);
        DB::table('services')->where('code', 'PAGCOR')->update(['is_active' => true]);
        $pagcor = DB::table('services')->where('code', 'PAGCOR')->value('id');
        DB::table('counters')->where('service_id', $pagcor)->update(['is_active' => true]);
    }
};
