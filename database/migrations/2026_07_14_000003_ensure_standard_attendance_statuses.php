<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['PRESENT' => 'Present', 'ABSENT' => 'Absent', 'LATE' => 'Late', 'SICK' => 'Sick', 'EXCUSED' => 'Excused', 'PERMISSION' => 'Permission', 'ACTIVITY' => 'Activity', 'SUSPENDED' => 'Suspended'] as $code => $name) {
            $existing = DB::table('attendance_statuses')->whereRaw('UPPER(status_code)=?', [$code])->orWhereRaw('LOWER(status_name)=?', [strtolower($name)])->first();
            if ($existing) {
                DB::table('attendance_statuses')->where('id', $existing->id)->update(['status_code' => $code, 'active' => true]);
            } else {
                DB::table('attendance_statuses')->insert(['id' => (string) Str::uuid(), 'status_name' => $name, 'status_code' => $code, 'active' => true, 'created_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
