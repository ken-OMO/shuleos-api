<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['account_number' => $this['account_number'], 'currency' => $this['currency'], 'opening_balance' => $this['opening_balance'], 'entries' => $this['entries'], 'closing_balance' => $this['closing_balance']];
    }
}
