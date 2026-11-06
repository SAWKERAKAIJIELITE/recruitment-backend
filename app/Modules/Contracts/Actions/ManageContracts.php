<?php

namespace App\Modules\Contracts\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Documents\Models\Document;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Models\Application;
use App\Support\ArabicText;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The contracts of an application (analysis doc §08; D-064):
 *  - write the first contract: only for an application awaiting its contract (its interview passed); the application
 *    becomes contract_written, with the contract as the cause, dated the day it was written;
 *  - renew the latest contract: a new contract in the chain, starting at its end or later;
 *  - correct a contract's details, with a reason (audited);
 *  - end the latest contract without renewal, with a date and a reason: the application becomes contract_ended
 *    (the person may then be nominated again, D-037).
 *
 * Contract fields: contract_type_id, reference_number, written_on, starts_on, ends_on (or null), document_id, notes.
 */
class ManageContracts
{
    public const FIELDS = ['contract_type_id', 'reference_number', 'written_on', 'starts_on', 'ends_on', 'document_id', 'notes'];

    public function __construct(private readonly ApplicationLifecycle $lifecycle) {}

    public function write(Application $application, array $data, StaffUser $actor): Contract
    {
        return DB::transaction(function () use ($application, $data, $actor) {
            $application = $this->lockApplication($application);

            if ($application->current_status !== ApplicationStatus::AwaitingContract) {
                $this->fail('application', 'contracts.not_awaiting_contract', ['status' => $application->current_status->labelAr()]);
            }

            $contract = $this->create($application, null, $data, $actor);

            try {
                $this->lifecycle->move($application, ApplicationStatus::ContractWritten, DecisionType::Outcome, $actor, null, $contract, $this->dayOf($contract->written_on));
            } catch (IllegalStatusTransition) {
                $this->fail('written_on', 'contracts.written_before_previous_decision');
            }

            AuditTrail::record('contract.written', $actor, ['contract_id' => $contract->id, 'application_id' => $application->id]);

            return $contract;
        });
    }

    public function renew(Contract $previous, array $data, StaffUser $actor): Contract
    {
        return DB::transaction(function () use ($previous, $data, $actor) {
            $application = $this->lockApplication($previous->application);
            $previous = Contract::query()->whereKey($previous->id)->lockForUpdate()->firstOrFail();

            if ($application->current_status !== ApplicationStatus::ContractWritten) {
                $this->fail('application', 'contracts.application_not_under_contract', ['status' => $application->current_status->labelAr()]);
            }

            if ($previous->ended_on !== null || $previous->renewal()->exists()) {
                $this->fail('contract', 'contracts.not_latest');
            }

            if ($previous->ends_on === null) {
                $this->fail('contract', 'contracts.renew_without_end');
            }

            if (Date::parse($data['starts_on'])->lessThan($previous->ends_on)) {
                $this->fail('starts_on', 'contracts.renewal_starts_before_end', ['date' => $previous->ends_on->toDateString()]);
            }

            $contract = $this->create($application, $previous, $data, $actor);

            AuditTrail::record('contract.renewed', $actor, ['contract_id' => $contract->id, 'renews_contract_id' => $previous->id, 'application_id' => $application->id]);

            return $contract;
        });
    }

    /** @param array<string, mixed> $changes some of FIELDS */
    public function correct(Contract $contract, array $changes, string $reason, StaffUser $actor): Contract
    {
        return DB::transaction(function () use ($contract, $changes, $reason, $actor) {
            $this->lockApplication($contract->application);
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $values = $this->values($contract->application, array_intersect_key($changes, array_flip(self::FIELDS)), $contract);

            $before = collect(array_keys($values))->mapWithKeys(fn (string $field) => [$field => $this->plain($contract->getAttribute($field))])->all();
            $contract->forceFill($values);
            $changed = array_keys($contract->getDirty());

            if ($changed === []) {
                $this->fail('reason', 'contracts.nothing_to_correct');
            }

            $this->assertPeriod($contract);
            $renewal = $contract->renewal()->first();

            if ($renewal !== null && ($contract->ends_on === null || $contract->ends_on->greaterThan($renewal->starts_on))) {
                $this->fail('ends_on', 'contracts.ends_after_renewal', ['date' => $renewal->starts_on->toDateString()]);
            }

            if ($contract->renews_contract_id !== null && $contract->starts_on->lessThan($contract->renews->ends_on)) {
                $this->fail('starts_on', 'contracts.renewal_starts_before_end', ['date' => $contract->renews->ends_on->toDateString()]);
            }

            if (in_array('written_on', $changed, true)) {
                $this->assertWritingDay($contract);
            }

            if (in_array('ends_on', $changed, true)) {
                $contract->expiry_reminded_at = null;   // a new end date: a new reminder
            }

            $contract->save();

            AuditTrail::record('contract.corrected', $actor, [
                'contract_id' => $contract->id,
                'reason' => trim($reason),
                'changes' => collect($changed)->mapWithKeys(fn (string $field) => [$field => ['from' => $before[$field] ?? null, 'to' => $this->plain($contract->getAttribute($field))]])->all(),
            ]);

            return $contract;
        });
    }

    public function end(Contract $contract, CarbonInterface $endedOn, string $reason, StaffUser $actor): Contract
    {
        return DB::transaction(function () use ($contract, $endedOn, $reason, $actor) {
            $application = $this->lockApplication($contract->application);
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if ($application->current_status !== ApplicationStatus::ContractWritten) {
                $this->fail('application', 'contracts.application_not_under_contract', ['status' => $application->current_status->labelAr()]);
            }

            if ($contract->ended_on !== null || $contract->renewal()->exists()) {
                $this->fail('contract', 'contracts.not_latest');
            }

            if ($endedOn->lessThan($contract->written_on)) {
                $this->fail('ended_on', 'contracts.ended_before_written', ['date' => $contract->written_on->toDateString()]);
            }

            $contract->forceFill([
                'ended_on' => $endedOn->toDateString(),
                'end_reason' => trim($reason),
                'ended_by' => $actor->id,
                'ended_at' => Date::now(),
            ])->save();

            try {
                $this->lifecycle->move($application, ApplicationStatus::ContractEnded, DecisionType::Outcome, $actor, trim($reason), $contract, $this->dayOf($contract->ended_on));
            } catch (IllegalStatusTransition) {
                $this->fail('ended_on', 'contracts.ended_before_previous_decision');
            }

            AuditTrail::record('contract.ended', $actor, ['contract_id' => $contract->id, 'application_id' => $application->id, 'ended_on' => $contract->ended_on->toDateString()]);

            return $contract;
        });
    }

    private function create(Application $application, ?Contract $previous, array $data, StaffUser $actor): Contract
    {
        $contract = new Contract;
        $contract->forceFill($this->values($application, array_intersect_key($data, array_flip(self::FIELDS))) + [
            'application_id' => $application->id,
            'sequence' => $previous === null ? 1 : $previous->sequence + 1,
            'renews_contract_id' => $previous?->id,
            'created_by' => $actor->id,
        ]);
        $this->assertPeriod($contract);
        $contract->save();

        return $contract;
    }

    /** Normalized values, with the reference number free and the file of this application. */
    private function values(Application $application, array $data, ?Contract $contract = null): array
    {
        if (array_key_exists('reference_number', $data)) {
            $data['reference_number'] = ArabicText::digitsToAscii(trim((string) $data['reference_number']));
            $taken = Contract::query()->where('reference_number', $data['reference_number'])->when($contract, fn ($query) => $query->whereKeyNot($contract->id))->exists();

            if ($taken) {
                $this->fail('reference_number', 'contracts.reference_taken');
            }
        }

        if (isset($data['document_id'])) {
            $document = Document::query()->find($data['document_id']);

            if ($document === null || $document->application_id !== $application->id || $document->archived_at !== null) {
                $this->fail('document_id', 'contracts.document_not_of_application');
            }

            if (Contract::query()->where('document_id', $document->id)->when($contract, fn ($query) => $query->whereKeyNot($contract->id))->exists()) {
                $this->fail('document_id', 'contracts.document_taken');
            }
        }

        return $data;
    }

    private function assertPeriod(Contract $contract): void
    {
        if ($contract->ends_on !== null && $contract->ends_on->lessThan($contract->starts_on)) {
            $this->fail('ends_on', 'contracts.ends_before_start');
        }
    }

    /**
     * A corrected writing day stays consistent with the record (otherwise the database refuses it or the history
     * contradicts it): not after the contract was ended, and for a first contract not before the decision it
     * followed (the interview passed). The status event keeps its date: the correction is in the audit log.
     */
    private function assertWritingDay(Contract $contract): void
    {
        if ($contract->ended_on !== null && $contract->written_on->greaterThan($contract->ended_on)) {
            $this->fail('written_on', 'contracts.written_after_end', ['date' => $contract->ended_on->toDateString()]);
        }

        if ($contract->renews_contract_id !== null) {
            return;
        }

        $before = DB::table('application_status_events as written')
            ->join('application_status_events as before', 'before.id', '=', 'written.previous_event_id')
            ->where('written.contract_id', $contract->id)
            ->where('written.to_status', ApplicationStatus::ContractWritten->value)
            ->value('before.occurred_at');

        if ($before !== null && $contract->written_on->toDateString() < Date::parse($before)->setTimezone(BusinessDay::timezone())->toDateString()) {
            $this->fail('written_on', 'contracts.written_before_decision', ['date' => Date::parse($before)->setTimezone(BusinessDay::timezone())->toDateString()]);
        }
    }

    /**
     * A decision dated a past day is placed at that day's end (after anything else that day); today means now. Days
     * are business days (Damascus, D-081).
     */
    private function dayOf(CarbonInterface $day): CarbonInterface
    {
        return BusinessDay::isToday($day) ? Date::now() : BusinessDay::endOf($day);
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : $value;
    }

    private function lockApplication(Application $application): Application
    {
        return Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
