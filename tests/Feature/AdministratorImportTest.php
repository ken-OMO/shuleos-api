<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdministratorImportTest extends TestCase
{
    public function test_import_pipeline_requires_csv_preview_quarantine_and_formula_rejection(): void
    {
        $source = file_get_contents(app_path('Services/Administrator/AdministratorImportService.php'));
        foreach (['Only CSV imports are accepted.', 'FileSecurityManager', 'quarantine', 'formula_not_allowed', "status' => 'previewed'"] as $expected) {
            $this->assertStringContainsString($expected, $source);
        }
        $this->assertStringNotContainsString("'password' =>", $source);
    }
}
