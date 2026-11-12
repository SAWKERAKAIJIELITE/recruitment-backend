<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Contracts\Actions\ManageContracts;
use App\Modules\Contracts\Exports\ConfirmationFile;
use App\Modules\Contracts\Exports\ContractForm;
use App\Modules\Contracts\Http\Requests\ContractRequest;
use App\Modules\Contracts\Http\Requests\CorrectContractRequest;
use App\Modules\Contracts\Http\Requests\EndContractRequest;
use App\Modules\Contracts\Http\Requests\RenewContractRequest;
use App\Modules\Contracts\Http\Resources\ContractResource;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Support\ContractSearch;
use App\Modules\Recruitment\Models\Application;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Contracts (analysis doc §08; D-064). Reading: contracts.view (the Contracts office and the general manager). Writing, renewing,
 * correcting and ending: contracts.manage.
 */
class ContractController extends Controller
{
    /**
     * List contracts
     *
     * Soonest end first (no end date last), 25 per page (`per_page` 5 to 100). Filters: `state` (upcoming, active,
     * ending_soon, expired, renewed, ended; `expired` = the end date passed, not renewed and not ended by staff), `q`
     * (name, nomination or reference number), `contract_type_id`, `unit_id`, `job_position_id`, `nominating_entity_id`,
     * `residence_district_id`, `residence_area_id` (the application's), `written_from` / `written_to` (the writing
     * date) and `ends_from` / `ends_to` (the end date; YYYY-MM-DD, included), `latest` (1: only the latest contract of
     * each application).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(ContractSearch::rules() + [
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $contracts = ContractSearch::apply(Contract::query()->with(ContractResource::RELATIONS), $filters)
            ->orderByRaw('ends_on asc nulls last')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return ContractResource::collection($contracts);
    }

    /**
     * ملف التثبيت (Excel)
     *
     * The owner's «نموذج تثبيت متعاقد مدني» (D-098): one contract per row, every column filled but the four of شعبة
     * الخدمة (رقم الكتاب, تاريخ منح الرقم, تاريخ مباشرة العمل, ملاحظات فرع الخدمة). Takes the list's filters (for
     * example `written_from` / `written_to` for the contracts written in a period, `latest` 1), ordered by writing date
     * then reference number. At most 20,000 rows (422 on `format` beyond: narrow the filters). Audited.
     */
    public function confirmationFile(Request $request): BinaryFileResponse
    {
        $filters = $request->validate(ContractSearch::rules());
        $query = ContractSearch::apply(Contract::query(), $filters);
        $max = (int) config('reports.max_rows.xlsx');

        if (($count = (clone $query)->count()) > $max) {
            throw ValidationException::withMessages(['format' => [__('reports.too_many_rows', ['count' => $count, 'max' => $max])]]);
        }

        $contracts = $query->with(ConfirmationFile::RELATIONS)->orderBy('written_on')->orderBy('reference_number')->get();
        AuditTrail::record('contract.confirmation_file', $request->user(), ['rows' => $contracts->count(), 'filters' => array_filter($filters, fn($value) => $value !== null)]);

        return response()
            ->download(ConfirmationFile::write($contracts, $request->user()), ConfirmationFile::filename(), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Content-Type-Options' => 'nosniff',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * The contract form (PDF)
     *
     * تعاقد عمل, the owner's template filled (D-098): the contract's form details, the person's and the application's,
     * the period and its length, dated the writing day in the Hijri and Gregorian calendars. The signatures and the photo
     * are left for the paper; a detail not entered is left blank. Audited.
     */
    public function form(Request $request, Contract $contract): Response
    {
        $pdf = ContractForm::render($contract);
        AuditTrail::record('contract.form_printed', $request->user(), ['contract_id' => $contract->id, 'application_id' => $contract->application_id]);
        $name = __('contracts.form_title', [], 'ar') . ' ' . $contract->reference_number . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="contract.pdf"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
