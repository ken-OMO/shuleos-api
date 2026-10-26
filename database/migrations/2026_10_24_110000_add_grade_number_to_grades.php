<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grades', function (Blueprint $table): void {
            $table->smallInteger('grade_number')->nullable();
        });

        DB::statement(
            'ALTER TABLE grades ADD CONSTRAINT grades_grade_number_check '
            .'CHECK (grade_number BETWEEN 0 AND 12)'
        );

        // Infer only standard names. Never infer classification from sort order.
        DB::table('grades')->select('id', 'grade_name')
            ->orderBy('id')->chunk(250, function ($grades): void {
                foreach ($grades as $grade) {
                    $name = trim((string) $grade->grade_name);
                    $number = null;

                    if (preg_match('/^PP\s*[12]$/i', $name) === 1) {
                        $number = 0;
                    } elseif (preg_match('/^grade\s*(1[0-2]|[1-9])$/i', $name, $matches) === 1) {
                        $number = (int) $matches[1];
                    }

                    if ($number !== null) {
                        DB::table('grades')->where('id', $grade->id)
                            ->update(['grade_number' => $number]);
                    }
                }
            });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Grade classification must be preserved. Use a forward migration.'
        );
    }
};
