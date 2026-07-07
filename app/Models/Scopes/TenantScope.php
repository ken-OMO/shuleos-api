<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply tenant filtering.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (

            app()->runningInConsole()

        ) {

            return;

        }

        $user = auth()->user();

        if (

            $user

            && isset($user->school_id)

        ) {

            $builder->where(

                $model->getTable().'.school_id',

                $user->school_id

            );

        }
    }
}
