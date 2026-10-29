<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\ManageExamSubjects;
use App\Modules\Exams\Http\Requests\ExamSubjectPartRequest;
use App\Modules\Exams\Http\Requests\ExamSubjectRequest;
use App\Modules\Exams\Http\Resources\ExamSubjectPartResource;
use App\Modules\Exams\Http\Resources\ExamSubjectResource;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\ExamSubjectPart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Exam subjects and their parts (analysis doc §05). Reading: exam_bank.manage, candidates.manage or
 * reports.view. Changing: exam_bank.manage. Subjects and parts are archived, never deleted.
 */
class ExamSubjectController extends Controller
{
    /**
     * List subjects
     *
     * Each subject with its parts. Archived subjects and parts are hidden unless `include_archived` is `1` or `true`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['include_archived' => ['nullable', Rule::in(['0', '1', 'true', 'false'])]]);
        $archived = $request->boolean('include_archived');

        $subjects = ExamSubject::query()
            ->when($archived, fn($query) => $query->withTrashed())
            ->with(['parts' => fn($query) => $query->when($archived, fn($query) => $query->withTrashed())])
            ->orderBy('id')
            ->get();

        return ExamSubjectResource::collection($subjects);
    }

    /**
     * Add a subject
     *
     * Optionally with its parts (for example القرآن الكريم with التلاوة 60 and التجويد 40). A name used
     * before, in any spelling, is refused (422).
     */
    public function store(ExamSubjectRequest $request, ManageExamSubjects $manage): JsonResponse
    {
        $subject = $manage->create($request->validated('name_ar'), $request->validated('parts') ?? [], $request->user());

        return ExamSubjectResource::make($subject)->response()->setStatusCode(201);
    }

    /** Rename a subject. */
    public function update(ExamSubjectRequest $request, int $id, ManageExamSubjects $manage): ExamSubjectResource
    {
        return $this->resource($manage->rename($this->subject($id), $request->validated('name_ar'), $request->user()));
    }

    /** Archive a subject: plans that use it keep it, new plans cannot add it. */
    public function archive(Request $request, int $id, ManageExamSubjects $manage): ExamSubjectResource
    {
        return $this->resource($manage->archive($this->subject($id), $request->user()));
    }

    /** Bring an archived subject back. */
    public function restore(Request $request, int $id, ManageExamSubjects $manage): ExamSubjectResource
    {
        return $this->resource($manage->restore($this->subject($id), $request->user()));
    }

    /**
     * Add a part to a subject
     *
     * `default_weight` is the part's weight inside its subject, relative to the other parts; `default_assessment` is
     * `exam` (default) or `manual` (marked by hand, like التجويد). A plan may set its own.
     */
    public function storePart(ExamSubjectPartRequest $request, int $id, ManageExamSubjects $manage): JsonResponse
    {
        $part = $manage->addPart($this->subject($id), $request->validated('name_ar'), $request->validated('default_weight'), $request->validated('default_assessment', 'exam'), $request->user());

        return ExamSubjectPartResource::make($part)->response()->setStatusCode(201);
    }

    /**
     * Change a part
     *
     * Its name, default weight or default assessment. Published plans keep what they were published with.
     */
    public function updatePart(ExamSubjectPartRequest $request, int $id, ManageExamSubjects $manage): ExamSubjectPartResource
    {
        return new ExamSubjectPartResource($manage->updatePart($this->part($id), $request->validated(), $request->user()));
    }

    /** Archive a part. */
    public function archivePart(Request $request, int $id, ManageExamSubjects $manage): ExamSubjectPartResource
    {
        return new ExamSubjectPartResource($manage->archivePart($this->part($id), $request->user()));
    }

    /** Bring an archived part back. */
    public function restorePart(Request $request, int $id, ManageExamSubjects $manage): ExamSubjectPartResource
    {
        return new ExamSubjectPartResource($manage->restorePart($this->part($id), $request->user()));
    }

    private function resource(ExamSubject $subject): ExamSubjectResource
    {
        return new ExamSubjectResource($subject->load(['parts' => fn($query) => $query->withTrashed()]));
    }

    private function subject(int $id): ExamSubject
    {
        return ExamSubject::withTrashed()->findOrFail($id);
    }

    private function part(int $id): ExamSubjectPart
    {
        return ExamSubjectPart::withTrashed()->findOrFail($id);
    }
}
