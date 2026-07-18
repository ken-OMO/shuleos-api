<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ParentStatementResource extends ParentPortalArrayResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['entries'] = collect($data['entries'] ?? [])->map(fn ($entry) => collect((array) $entry)->only(['transaction_date', 'transaction_type', 'debit_amount', 'credit_amount', 'running_balance', 'description'])->all())->all();

        return $data;
    }
}
