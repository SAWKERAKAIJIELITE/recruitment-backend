<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamPlanSubject;
use App\Modules\Exams\Models\ExamPlanSubjectPart;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Recruitment\Http\Resources\ReferenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExamPlanVersion */
class ExamPlanResource extends JsonResource
{
    public const RELATIONS = ['jobPosition', 'passPolicy', 'creator', 'publisher', 'subjects.subject', 'subjects.parts.part'];

    public function toArray(Request $request): array
    {
        $person = fn($user) => $user === null ? null : ['id' => $user->id, 'username' => $user->username, 'full_name' => $user->full_name];

        return [
            'id' => $this->id,
            'job_position' => ReferenceSummary::of($this->jobPosition),
            'version' => $this->version,
            'status' => $this->status->value,
            /** The single exam session; manual parts are marked outside it. */
            'duration_minutes' => $this->duration_minutes,
            /** Online questions of the whole exam. */
            'question_count' => $this->questionCount(),
            'notes' => $this->notes,
            'pass_policy' => [
                'version' => $this->passPolicy->version,
                'subject_pass_mark' => (string) $this->passPolicy->subject_pass_mark,
                'tolerance_floor' => (string) $this->passPolicy->tolerance_floor,
                'max_tolerated_subjects' => $this->passPolicy->max_tolerated_subjects,
                'average_must_exceed' => (string) $this->passPolicy->average_must_exceed,
            ],
            'subjects' => $this->subjects->map(fn(ExamPlanSubject $subject) => [
                'subject' => ReferenceSummary::of($subject->subject),
                'position' => $subject->position,
                'scored_from_parts' => $subject->scoredFromParts(),
                /** Whole subject: {easy, medium, hard}; null when scored from parts. */
                'questions' => $subject->difficultyCounts(),
                'question_count' => $subject->totalQuestions(),
                'parts' => $subject->parts->map(fn(ExamPlanSubjectPart $part) => [
                    'part' => ReferenceSummary::of($part->part),
                    'weight' => (string) $part->weight,
                    /** exam or manual (marked by hand outside the session). */
                    'assessment' => $part->assessment->value,
                    /** Exam part: {easy, medium, hard}; null for a manual part. */
                    'questions' => $part->difficultyCounts(),
                    /** Manual part: the scale staff mark it out of. */
                    'max_score' => $part->max_score === null ? null : (string) $part->max_score,
                ])->values()->all(),
            ])->values()->all(),
            'created_by' => $person($this->creator),
            'published_by' => $person($this->publisher),
            'published_at' => $this->published_at?->toIso8601String(),
            'retired_at' => $this->retired_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
