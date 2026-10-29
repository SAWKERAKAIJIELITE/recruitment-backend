<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Actions\ManageQuestions;
use App\Modules\Exams\Enums\Difficulty;
use App\Modules\Exams\Http\Requests\ExamQuestionRequest;
use App\Modules\Exams\Http\Resources\ExamQuestionResource;
use App\Modules\Exams\Models\ExamQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The question bank (analysis doc §05, D-055). Only exam_bank.manage reads or changes it: it holds the answers.
 */
class ExamQuestionController extends Controller
{
    /**
     * List questions
     *
     * Newest first, 50 per page. Filters: `subject_id`, `part_id` (`none` for questions of the subject itself),
     * `difficulty`, `q` (words of the current prompt), `include_archived`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'subject_id' => ['nullable', 'integer'],
            'part_id' => ['nullable', 'regex:/^(\d+|none)$/'],
            'difficulty' => ['nullable', Rule::enum(Difficulty::class)],
            'q' => ['nullable', 'string', 'max:200'],
            'include_archived' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
        ]);

        $questions = ExamQuestion::query()
            ->with(ExamQuestionResource::RELATIONS)
            ->when($request->boolean('include_archived'), fn($query) => $query->withTrashed())
            ->when(isset($filters['subject_id']), fn($query) => $query->where('subject_id', $filters['subject_id']))
            ->when(($filters['part_id'] ?? null) === 'none', fn($query) => $query->whereNull('part_id'))
            ->when(ctype_digit((string) ($filters['part_id'] ?? '')), fn($query) => $query->where('part_id', $filters['part_id']))
            ->when(isset($filters['difficulty']), fn($query) => $query->where('difficulty', $filters['difficulty']))
            ->when(isset($filters['q']), fn($query) => $query->whereHas('currentVersion', fn($version) => $version->where('prompt', 'ilike', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['q'])) . '%')))
            ->orderByDesc('id')
            ->paginate(50);

        return ExamQuestionResource::collection($questions);
    }

    /**
     * How many questions the bank holds
     *
     * Active questions per subject, part (null: the subject itself) and difficulty.
     */
    public function stats(): JsonResponse
    {
        $rows = DB::table('exam_questions')
            ->whereNull('deleted_at')
            ->selectRaw('subject_id, part_id, difficulty, count(*) AS questions')
            ->groupBy('subject_id', 'part_id', 'difficulty')
            ->orderBy('subject_id')->orderBy('part_id')->orderBy('difficulty')
            ->get()
            ->map(fn($row) => ['subject_id' => (int) $row->subject_id, 'part_id' => $row->part_id === null ? null : (int) $row->part_id, 'difficulty' => $row->difficulty, 'questions' => (int) $row->questions]);

        return response()->json(['data' => $rows]);
    }

    /** One question with all its versions. */
    public function show(int $id): ExamQuestionResource
    {
        return new ExamQuestionResource($this->question($id)->load([...ExamQuestionResource::RELATIONS, 'versions']));
    }

    /** Add a question. */
    public function store(ExamQuestionRequest $request, ManageQuestions $questions): JsonResponse
    {
        $data = $request->validated();
        $question = $questions->create(
            (int) $data['subject_id'],
            isset($data['part_id']) ? (int) $data['part_id'] : null,
            Difficulty::from($data['difficulty']),
            $data['prompt'],
            $data['options'],
            (int) $data['correct_option'],
            $request->user(),
        );

        return ExamQuestionResource::make($question->load(ExamQuestionResource::RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * Revise a question
     *
     * A new version when the prompt, options or answer change (sessions keep the version they used); the
     * difficulty changes in place.
     */
    public function update(ExamQuestionRequest $request, int $id, ManageQuestions $questions): ExamQuestionResource
    {
        $question = $questions->revise($this->question($id), $request->validated(), $request->user());

        return new ExamQuestionResource($question->load([...ExamQuestionResource::RELATIONS, 'versions']));
    }

    /** Archive a question: it is no longer drawn for new sessions. */
    public function archive(Request $request, int $id, ManageQuestions $questions): ExamQuestionResource
    {
        return new ExamQuestionResource($questions->archive($this->question($id), $request->user())->load(ExamQuestionResource::RELATIONS));
    }

    /** Bring an archived question back. */
    public function restore(Request $request, int $id, ManageQuestions $questions): ExamQuestionResource
    {
        return new ExamQuestionResource($questions->restore($this->question($id), $request->user())->load(ExamQuestionResource::RELATIONS));
    }

    private function question(int $id): ExamQuestion
    {
        return ExamQuestion::withTrashed()->findOrFail($id);
    }
}
