<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use App\Core\Database\Transaction;
use App\Core\Identifier\Identifier;

abstract class BaseService
{
    /**
     * Generate a new unique identifier.
     */
    protected function newId(): string
    {
        return Identifier::generate();
    }

    /**
     * Execute code inside a database transaction.
     */
    protected function transaction(
        Closure $callback,
        int $attempts = 1
    ): mixed {

        return Transaction::run(

            callback: $callback,

            attempts: $attempts

        );

    }

    /**
     * Execute code without a transaction.
     */
    protected function withoutTransaction(
        Closure $callback
    ): mixed {

        return Transaction::withoutTransaction(

            $callback

        );

    }

    /**
     * Determine whether a transaction is active.
     */
    protected function inTransaction(): bool
    {
        return Transaction::active();
    }

    /**
     * Current immutable timestamp.
     */
    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    /**
     * Current authenticated user.
     */
    protected function user(): ?Authenticatable
    {
        return Auth::user();
    }

    /**
     * Current authenticated user ID.
     */
    protected function userId(): ?string
    {
        return $this->user()?->getAuthIdentifier();
    }
}
