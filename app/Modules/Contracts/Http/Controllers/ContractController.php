<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contracts\Actions\ManageContracts;
use App\Modules\Contracts\Enums\ContractState;
use App\Modules\Contracts\Http\Requests\ContractRequest;
use App\Modules\Contracts\Http\Requests\CorrectContractRequest;
use App\Modules\Contracts\Http\Requests\EndContractRequest;
use App\Modules\Contracts\Http\Requests\RenewContractRequest;
use App\Modules\Contracts\Http\Resources\ContractResource;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Recruitment\Models\Application;
use App\Support\ArabicText;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * Contracts (analysis doc §08; D-064). Reading: contracts.manage, candidates.manage or reports.view. Writing, renewing,
 * correcting and ending: contracts.manage.
 */
class ContractController extends Controller
{
    /**
     * List contracts
     *
     * Soonest end first (no end date last), 25 per page. Filters: `state` (upcoming, active, ending_soon, expired,
     * renewed, ended; `expired` = the end date passed, not renewed and not ended by staff), `q` (name, nomination or
     * reference number), `contract_type_id`, `job_position_id`, `nominating_entity_id`, `ends_from` / `ends_to`
     * (YYYY-MM-DD, included), `latest` (1: only the latest contract of each application).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::enum(ContractState::class)],
            'q' => ['nullable', 'string', 'max:150'],
            'contract_type_id' => ['nullable', 'integer'],
            'job_position_id' => ['nullable', 'integer'],
            'nominating_entity_id' => ['nullable', 'integer'],
            'ends_from' => ['nullable', 'date_format:Y-m-d'],
            'ends_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:ends_from'],
            'latest' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $contracts = Contract::query()
            ->with(ContractResource::RELATIONS)
            ->when($filters['state'] ?? null, fn($query, $state) => $query->inState(ContractState::from($state)))
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%' . addcslashes(ArabicText::searchKey(trim($q)), '\\%_') . '%';
                $plain = ArabicText::digitsToAscii(trim($q));

                $query->where(fn($inner) => $inner
                    ->where('contracts.reference_number', $plain)
                    ->orWhereHas('application', fn($application) => $application
                        ->where('nomination_number', $plain)
                        ->orWhereHas('candidate', fn($candidate) => $candidate->where('name_search_key', 'like', $like))));
            })
            ->when($filters['contract_type_id'] ?? null, fn($query, $value) => $query->where('contract_type_id', $value))
            ->when($filters['job_position_id'] ?? null, fn($query, $value) => $query->whereHas('application', fn($application) => $application->where('job_position_id', $value)))
            ->when($filters['nominating_entity_id'] ?? null, fn($query, $value) => $query->whereHas('application', fn($application) => $application->where('nominating_entity_id', $value)))
            ->when($filters['ends_from'] ?? null, fn($query, $date) => $query->where('ends_on', '>=', $date))
            ->when($filters['ends_to'] ?? null, fn($query, $date) => $query->where('ends_on', '<=', $date))
            ->when($request->boolean('latest'), fn($query) => $query->whereNotExists(fn($renewal) => $renewal->from('contracts as renewal')->whereColumn('renewal.renews_contract_id', 'contracts.id')))
            ->orderByRaw('ends_on asc nulls last')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return ContractResource::collection($contracts);
    }

    /** Every contract of one application, the first first. */
    public function forApplication(Application $application): AnonymousResourceCollection
    {
        return ContractResource::collection(Contract::query()->with(ContractResource::RELATIONS)->where('application_id', $application->id)->orderBy('sequence')->get());
    }

    public function show(Contract $contract): ContractResource
    {
        return $this->resource($contract);
    }

    /**
     * Write the first contract
     *
     * Only for an application awaiting its contract (its interview passed). The application becomes contract_written,
     * with the contract as the cause, dated `written_on`. 422 on `reference_number` when another contract has it.
     */
    public function store(ContractRequest $request, Application $application, ManageContracts $contracts): JsonResponse
    {
        return $this->resource($contracts->write($application, $request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    /**
     * Renew a contract
     *
     * The latest contract of an application that is under contract, with an end date. The renewal starts at that end
     * or later (`starts_on` defaults to the day after it). Renewing late, after the end date passed, is allowed.
     */
    public function renew(RenewContractRequest $request, Contract $contract, ManageContracts $contracts): JsonResponse
    {
        return $this->resource($contracts->renew($contract, $request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    /**
     * Correct a contract
     *
     * Send the fields that change and a `reason` (audited with the values before and after). A new `ends_on` sends a
     * new reminder when it comes close. A renewed contract cannot end after its renewal starts.
     */
    public function correct(CorrectContractRequest $request, Contract $contract, ManageContracts $contracts): ContractResource
    {
        return $this->resource($contracts->correct($contract, $request->changes(), $request->validated('reason'), $request->user()));
    }

    /**
     * End without renewal
     *
     * The latest contract, with `ended_on` (default today) and a `reason`. The application becomes contract_ended
     * (the person may be nominated again). Ending early, before the end date, is allowed.
     */
    public function end(EndContractRequest $request, Contract $contract, ManageContracts $contracts): ContractResource
    {
        $endedOn = Date::parse($request->validated('ended_on') ?? BusinessDay::today());

        return $this->resource($contracts->end($contract, $endedOn, $request->validated('reason'), $request->user()));
    }

    private function resource(Contract $contract): ContractResource
    {
        return new ContractResource($contract->load(ContractResource::RELATIONS));
    }
}
