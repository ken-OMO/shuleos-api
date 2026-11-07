<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class TransportController extends Controller
{
    protected function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->getAttribute('school_id') !== null
            && (string) $request->attributes->get('tenant_school_id') === (string) $user->school_id, 403);
        if ($request->attributes->get('client_supplied_school_id') === true) {
            throw ValidationException::withMessages(['school_id' => ['This field is prohibited.']]);
        }

        return $user;
    }

    protected function payload(Request $request): array
    {
        $this->actor($request);

        // Preserve every other input key so service whitelists reject unknown fields.
        return $request->except('school_id');
    }

    protected function emptyPayload(Request $request): void
    {
        foreach (array_keys($this->payload($request)) as $key) {
            throw ValidationException::withMessages([$key => ['This field is prohibited.']]);
        }
    }
}
