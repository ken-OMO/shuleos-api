<?php

declare(strict_types=1);

namespace App\Services\School;

use App\Models\School;
use Illuminate\Support\Facades\DB;

class SchoolSettingsProvisioningService
{
    public function provision(School $school): void
    {
        $exists = DB::table('school_settings')
            ->where('school_id', $school->id)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('school_settings')->insert([
            'school_id' => $school->id,
        ]);
    }
}
