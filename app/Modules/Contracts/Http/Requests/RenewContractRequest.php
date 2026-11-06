<?php

namespace App\Modules\Contracts\Http\Requests;

use App\Modules\Contracts\Models\Contract;
use App\Support\BusinessDay;

/** A renewal: as a first contract, but `starts_on` defaults to the day after the renewed contract ends. */
class RenewContractRequest extends ContractRequest
{
    protected function prepareForValidation(): void
    {
        $previous = $this->route('contract');
        $startsOn = $this->input('starts_on');

        if ($startsOn === null && $previous instanceof Contract && $previous->ends_on !== null) {
            $startsOn = $previous->ends_on->copy()->addDay()->toDateString();
        }

        $writtenOn = $this->input('written_on') ?? BusinessDay::today();

        // Without an end date there is no default: the renewal is refused anyway (contracts.renew_without_end).
        $this->merge(['written_on' => $writtenOn, 'starts_on' => $startsOn ?? $writtenOn]);
    }
}
