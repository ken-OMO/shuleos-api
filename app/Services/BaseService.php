<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Transaction;
use Closure;
use Illuminate\Support\Facades\Log;

abstract class BaseService
{
    /**
     * Execute a database transaction.
     */
    protected function transaction(
        Closure $callback,
        int $attempts = 1
    ): mixed {
        return Transaction::run(
            $callback,
            $attempts
        );
    }

    /**
     * Log an informational message.
     */
    protected function logInfo(
        string $message,
        array $context = []
    ): void {
        Log::info(
            $message,
            $context
        );
    }

    /**
     * Log a warning message.
     */
    protected function logWarning(
        string $message,
        array $context = []
    ): void {
        Log::warning(
            $message,
            $context
        );
    }

    /**
     * Log an error message.
     */
    protected function logError(
        string $message,
        array $context = []
    ): void {
        Log::error(
            $message,
            $context
        );
    }

    /**
     * Before creating a record.
     */
    protected function beforeCreate(
        array &$data
    ): void {
        //
    }

    /**
     * After creating a record.
     */
    protected function afterCreate(
        mixed $model
    ): void {
        //
    }

    /**
     * Before updating a record.
     */
    protected function beforeUpdate(
        mixed $model,
        array &$data
    ): void {
        //
    }

    /**
     * After updating a record.
     */
    protected function afterUpdate(
        mixed $model
    ): void {
        //
    }

    /**
     * Before deleting a record.
     */
    protected function beforeDelete(
        mixed $model
    ): void {
        //
    }

    /**
     * After deleting a record.
     */
    protected function afterDelete(
        mixed $model
    ): void {
        //
    }

    /**
     * Create a resource.
     */
    abstract public function create(
        array $data
    ): mixed;

    /**
     * Update a resource.
     */
    abstract public function update(
        string $id,
        array $data
    ): mixed;

    /**
     * Find a resource.
     */
    abstract public function find(
        string $id
    ): mixed;

    /**
     * List resources.
     */
    abstract public function list(
        int $perPage = 20
    ): mixed;
}
