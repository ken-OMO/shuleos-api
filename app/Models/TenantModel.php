<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

abstract class TenantModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::saving(function (Model $model): void {
            if (auth()->user()?->school_id) {
                $model->setAttribute('school_id', auth()->user()->school_id);
            }
        });
    }

    protected function performDeleteOnModel(): void
    {
        if (! Schema::hasColumn($this->getTable(), 'is_deleted')) {
            parent::performDeleteOnModel();

            return;
        }

        $attributes = ['is_deleted' => true];

        if (Schema::hasColumn($this->getTable(), 'active')) {
            $attributes['active'] = false;
        }

        if (Schema::hasColumn($this->getTable(), 'deleted_at')) {
            $attributes['deleted_at'] = $this->freshTimestamp();
        }

        if (Schema::hasColumn($this->getTable(), 'deleted_by')) {
            $attributes['deleted_by'] = auth()->id();
        }

        $this->newQueryWithoutScopes()->whereKey($this->getKey())->update($attributes);
        $this->forceFill($attributes)->syncOriginal();
    }
}
