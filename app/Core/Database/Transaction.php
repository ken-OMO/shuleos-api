<?php

declare(strict_types=1);

namespace App\Core\Database;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class Transaction
{
    /**
     * Prevent instantiation.
     */
    private function __construct() {}

    /**
     * Execute a callback within a database transaction.
     *
     * @template T
     *
     * @param  Closure():T  $callback
     * @return T
     *
     * @throws Throwable
     */
    public static function run(
        Closure $callback,
        int $attempts = 1
    ): mixed {
        return DB::transaction(
            callback: $callback,
            attempts: $attempts
        );
    }

    /**
     * Execute a callback without a transaction.
     *
     * Useful when a service wants a consistent API regardless
     * of whether a transaction is required.
     *
     * @template T
     *
     * @param  Closure():T  $callback
     * @return T
     */
    public static function withoutTransaction(
        Closure $callback
    ): mixed {
        return $callback();
    }

    /**
     * Determine whether a transaction is currently active.
     */
    public static function active(): bool
    {
        return DB::transactionLevel() > 0;
    }
}
