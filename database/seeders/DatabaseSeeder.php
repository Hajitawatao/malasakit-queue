<?php

namespace Database\Seeders;

use App\Models\Counter;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Dating 6 (Malasakit SW, DSWD, PhilHealth, DOH/MAIP, PCSO, PAGCOR)
        // -> 4 na lang: pinagsama ang MSS at DOH/MAIP, tinanggal ang PCSO.
        $services = [
            ['code' => 'DOH',    'name' => 'MSS / DOH-MAIP', 'spoken_name' => 'M S S, D O H MAIP', 'logo_path' => 'logos/doh.png'],
            ['code' => 'DSWD',   'name' => 'DSWD',           'spoken_name' => 'D S W D',           'logo_path' => 'logos/dswd.png'],
            ['code' => 'PHIC',   'name' => 'PhilHealth',     'spoken_name' => 'PhilHealth',        'logo_path' => 'logos/philhealth.png'],
            ['code' => 'PAGCOR', 'name' => 'PAGCOR',         'spoken_name' => 'PAGCOR',            'logo_path' => 'logos/pagcor.png'],
        ];

        foreach ($services as $i => $data) {
            $service = Service::updateOrCreate(['code' => $data['code']], $data + ['sort_order' => $i]);

            $counter = Counter::updateOrCreate(
                ['service_id' => $service->id, 'name' => "{$service->name} Window 1"],
                ['spoken_name' => 'Window 1']
            );

            User::updateOrCreate(
                ['email' => strtolower($service->code) . '1'],   // username: doh1, dswd1, phic1, pcso1
                [
                    'name' => "{$service->name} Staff 1",
                    'password' => Hash::make('changeme'),
                    'role' => 'staff',
                    'counter_id' => $counter->id,
                ]
            );
        }

        User::updateOrCreate(
            ['email' => 'admin'],
            ['name' => 'MIS Admin', 'password' => Hash::make('changeme'), 'role' => 'admin']
        );
    }
}
