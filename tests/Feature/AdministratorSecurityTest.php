<?php

namespace Tests\Feature;

use App\Http\Resources\AdministratorSafeResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class AdministratorSecurityTest extends TestCase
{
    public function test_sensitive_fields_are_recursively_redacted(): void
    {
        $data = (new AdministratorSafeResource(['password_hash' => 'x', 'storage_id' => 'x', 'nested' => ['push_token_encrypted' => 'x', 'visible' => true]]))->toArray(Request::create('/'));
        $this->assertSame(['nested' => ['visible' => true]], $data);
    }
}
