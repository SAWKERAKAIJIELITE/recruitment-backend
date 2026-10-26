<?php

namespace App\Modules\Recruitment\Actions;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Support\NationalNumber;
use App\Modules\Recruitment\Support\NominationNumberGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens an application for a person: finds or creates the person, takes the next nomination number,
 * and writes the first history event, all in one transaction. The Excel import will reuse it per row.
 */
class CreateApplication
{
    /** The application fields of the input (everything except the person). */
    private const APPLICATION_FIELDS = [
        'nominating_entity_id',
        'job_position_id',
        'application_category_id',
        'existing_employee_number',
        'academic_qualification_id',
        'specialization_id',
        'studying_years',
        'studies_discontinued',
        'quran_parts_memorized',
        'marital_status_id',
        'health_condition_id',
        'health_details',
        'residence_district_id',
        'residence_area_id',
        'residence_details',
        'phone_number',
        'works_for_other_government_entity',
        'full_commitment',
        'overnight_work',
        'notes',
    ];

    public function __construct(
        private readonly ApplicationLifecycle $lifecycle,
        private readonly NominationNumberGenerator $numbers,
    ) {
    }

    /**
     * @param  array  $input  validated data (see StoreApplicationRequest)
     * @return array{application: Application, candidate_matched: bool}
     */
    public function execute(array $input, StaffUser $actor): array
    {
        return DB::transaction(function () use ($input, $actor) {
            self::lockPeople([$input['candidate']['national_number']]);

            return $this->createLocked($input, $actor, $this->numbers->next());
        });
    }

    /**
     * For callers that open many applications in one transaction (the Excel import): no savepoint per
     * application. The caller must hold the transaction, have locked the people with lockPeople(), and
     * pass a nomination number it reserved.
     *
     * @return array{application: Application, candidate_matched: bool}
     */
    public function createLocked(array $input, StaffUser $actor, string $nominationNumber): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('CreateApplication::createLocked() must run inside a transaction.');
        }

        [$candidate, $matched] = $this->findOrCreateCandidate($input['candidate']);

        $this->assertNoLiveApplication($candidate);

        $fields = Arr::only($input, self::APPLICATION_FIELDS);
        $fields['studies_discontinued'] = (bool) ($fields['studies_discontinued'] ?? false);   // NOT NULL, default false

        $application = new Application($fields);
        $application->candidate_id = $candidate->id;
        $application->forceFill([
            'nomination_number' => $nominationNumber,
            'created_by' => $actor->id,
        ])->save();

        $this->lifecycle->open($application, $actor);

        $application->setRelation('candidate', $candidate);

        return ['application' => $application, 'candidate_matched' => $matched];
    }

    /**
     * Serializes everything that creates or opens applications for these people until the transaction
     * ends (a transaction-level advisory lock per national number, taken in a fixed order so two callers
     * cannot deadlock). Whoever holds the lock can trust what it reads about these people (D-041).
     *
     * @param  list<string>  $nationalNumbers
     */
    public static function lockPeople(array $nationalNumbers): void
    {
        $numbers = array_values(array_unique(array_map(fn(string $number) => NationalNumber::normalize($number), $nationalNumbers)));
        sort($numbers);

        if ($numbers === []) {
            return;
        }

        DB::select(
            "SELECT pg_advisory_xact_lock(hashtextextended('candidate:' || n, 0)) FROM unnest(?::text[]) WITH ORDINALITY AS t(n, i) ORDER BY i",
            ['{' . implode(',', $numbers) . '}'],
        );
    }

    /**
     * A person is recognized only by national number, never by name: two people can share a name.
     * A matched person keeps the data already on file; a new application never overwrites it.
     *
     * @return array{0: Candidate, 1: bool}
     */
    private function findOrCreateCandidate(array $data): array
    {
        $nationalNumber = NationalNumber::normalize($data['national_number']);

        // The caller holds this person's advisory lock (lockPeople), so no one else can create them meanwhile;
        // the unique index stays the last guard.
        $existing = Candidate::query()->where('national_number', $nationalNumber)->first();

        if ($existing !== null) {
            return [$existing, true];
        }

        return [Candidate::create(['national_number' => $nationalNumber] + $data), false];
    }

    /** One live application per person, whatever the job position (D-037). */
    private function assertNoLiveApplication(Candidate $candidate): void
    {
        $live = Application::query()->where('candidate_id', $candidate->id)->live()->value('nomination_number');

        if ($live !== null) {
            throw ValidationException::withMessages([
                'candidate.national_number' => [__('recruitment.live_application_exists', ['number' => $live])],
            ]);
        }
    }
}
