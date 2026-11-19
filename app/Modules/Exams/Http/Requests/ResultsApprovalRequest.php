<?php

namespace App\Modules\Exams\Http\Requests;

use App\Modules\Recruitment\Enums\DecisionReasonType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Approving a session's results, with the passes failed by decision (D-104). */
class ResultsApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route's permission (exams.manage); failures also need exceptions.approve (the controller)
    }

    public function rules(): array
    {
        return [
            /** Candidates who passed and are failed by decision (ترسيب بقرار); empty or absent: everyone as the marks say. */
            'failures' => ['nullable', 'array', 'max:500'],
            /** The booking of this session (a pass waiting for the approval). */
            'failures.*.booking_id' => ['required', 'uuid', 'distinct'],
            /** cheating or other. */
            'failures.*.reason_type' => ['required', Rule::enum(DecisionReasonType::class)],
            'failures.*.reason' => ['required', 'string', 'min:3', 'max:1000'],
            /** رقم القرار (optional). */
            'failures.*.decision_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'failures' => 'المرسَّبون بقرار',
            'failures.*.booking_id' => 'الحجز',
            'failures.*.reason_type' => 'نوع السبب',
            'failures.*.reason' => 'السبب',
            'failures.*.decision_number' => 'رقم القرار',
        ];
    }

    /** @return list<array{booking_id: string, reason_type: string, reason: string, decision_number: ?string}> */
    public function failures(): array
    {
        return array_values(array_map(fn(array $failure) => [
            'booking_id' => $failure['booking_id'],
            'reason_type' => $failure['reason_type'],
            'reason' => trim($failure['reason']),
            'decision_number' => isset($failure['decision_number']) && trim($failure['decision_number']) !== '' ? trim($failure['decision_number']) : null,
        ], $this->validated('failures') ?? []));
    }
}
