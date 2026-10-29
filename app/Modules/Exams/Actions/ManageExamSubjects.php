<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\ExamSubjectPart;
use App\Modules\Identity\Models\StaffUser;
use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Subjects and their parts. Names are unique in any spelling (subjects across the list, parts within their
 * subject). Archived subjects and parts stay valid for the plans that already use them (published plans keep
 * their own copy of every weight and count, D-053) but cannot be added to a plan.
 */
class ManageExamSubjects
{
    /** @param list<array{name_ar: string, default_weight: string|int, default_assessment?: string}> $parts */
    public function create(string $name, array $parts, StaffUser $actor): ExamSubject
    {
        return DB::transaction(function () use ($name, $parts, $actor) {
            $this->assertSubjectNameIsFree($name, null);

            $subject = ExamSubject::create(['name_ar' => $name]);

            foreach ($parts as $index => $part) {
                $this->assertPartNameIsFree($subject, $part['name_ar'], null, "parts.{$index}.name_ar");
                $this->newPart($subject, $part['name_ar'], $part['default_weight'], $part['default_assessment'] ?? 'exam');
            }

            AuditTrail::record('exam_subject.created', $actor, ['subject_id' => $subject->id, 'name' => $subject->name_ar]);

            return $subject->load('parts');
        });
    }

    public function rename(ExamSubject $subject, string $name, StaffUser $actor): ExamSubject
    {
        $this->assertSubjectNameIsFree($name, $subject->id);
        $from = $subject->name_ar;
        $subject->update(['name_ar' => $name]);
        AuditTrail::record('exam_subject.renamed', $actor, ['subject_id' => $subject->id, 'from' => $from, 'to' => $subject->name_ar]);

        return $subject;
    }

    public function archive(ExamSubject $subject, StaffUser $actor): ExamSubject
    {
        $subject->delete();
        AuditTrail::record('exam_subject.archived', $actor, ['subject_id' => $subject->id]);

        return $subject;
    }

    public function restore(ExamSubject $subject, StaffUser $actor): ExamSubject
    {
        $subject->restore();
        AuditTrail::record('exam_subject.restored', $actor, ['subject_id' => $subject->id]);

        return $subject;
    }

    public function addPart(ExamSubject $subject, string $name, string|int $weight, string $assessment, StaffUser $actor): ExamSubjectPart
    {
        $this->assertPartNameIsFree($subject, $name, null, 'name_ar');
        $part = $this->newPart($subject, $name, $weight, $assessment);
        AuditTrail::record('exam_subject_part.created', $actor, ['subject_id' => $subject->id, 'part_id' => $part->id, 'weight' => (string) $part->default_weight]);

        return $part;
    }

    /** @param array{name_ar?: string, default_weight?: string|int, default_assessment?: string} $changes */
    public function updatePart(ExamSubjectPart $part, array $changes, StaffUser $actor): ExamSubjectPart
    {
        if (isset($changes['name_ar'])) {
            $this->assertPartNameIsFree($part->subject, $changes['name_ar'], $part->id, 'name_ar');
        }

        $before = ['name_ar' => $part->name_ar, 'default_weight' => (string) $part->default_weight, 'default_assessment' => $part->default_assessment->value];
        $part->fill($changes)->save();

        AuditTrail::record('exam_subject_part.updated', $actor, [
            'part_id' => $part->id,
            'from' => $before,
            'to' => ['name_ar' => $part->name_ar, 'default_weight' => (string) $part->default_weight, 'default_assessment' => $part->default_assessment->value],
        ]);

        return $part;
    }

    public function archivePart(ExamSubjectPart $part, StaffUser $actor): ExamSubjectPart
    {
        $part->delete();
        AuditTrail::record('exam_subject_part.archived', $actor, ['part_id' => $part->id]);

        return $part;
    }

    public function restorePart(ExamSubjectPart $part, StaffUser $actor): ExamSubjectPart
    {
        $part->restore();
        AuditTrail::record('exam_subject_part.restored', $actor, ['part_id' => $part->id]);

        return $part;
    }

    private function newPart(ExamSubject $subject, string $name, string|int $weight, string $assessment): ExamSubjectPart
    {
        $part = new ExamSubjectPart(['name_ar' => $name, 'default_weight' => $weight, 'default_assessment' => $assessment]);
        $part->subject_id = $subject->id;
        $part->save();

        return $part;
    }

    private function assertSubjectNameIsFree(string $name, ?int $exceptId): void
    {
        $clash = ExamSubject::withTrashed()
            ->where('name_key', ArabicText::searchKey($name))
            ->when($exceptId !== null, fn($query) => $query->whereKeyNot($exceptId))
            ->first(['id', 'deleted_at']);

        if ($clash !== null) {
            throw ValidationException::withMessages(['name_ar' => [__($clash->trashed() ? 'recruitment.name_archived' : 'recruitment.name_taken')]]);
        }
    }

    private function assertPartNameIsFree(ExamSubject $subject, string $name, ?int $exceptId, string $field): void
    {
        $clash = ExamSubjectPart::withTrashed()
            ->where('subject_id', $subject->id)
            ->where('name_key', ArabicText::searchKey($name))
            ->when($exceptId !== null, fn($query) => $query->whereKeyNot($exceptId))
            ->first(['id', 'deleted_at']);

        if ($clash !== null) {
            throw ValidationException::withMessages([$field => [__($clash->trashed() ? 'recruitment.name_archived' : 'recruitment.name_taken')]]);
        }
    }
}
