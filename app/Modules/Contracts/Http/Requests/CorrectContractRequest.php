<?php

namespace App\Modules\Contracts\Http\Requests;

use App\Modules\Contracts\Actions\ManageContracts;
use Illuminate\Foundation\Http\FormRequest;

/** The contract fields that change and the `reason`. */
class CorrectContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route middleware decides
    }

    public function rules(): array
    {
        return ContractRequest::fieldRules(partial: true) + ['reason' => ['required', 'string', 'min:3', 'max:2000']];
    }

    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip(ManageContracts::FIELDS));
    }
}
