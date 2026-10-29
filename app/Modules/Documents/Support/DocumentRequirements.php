<?php

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Support\Facades\DB;

/**
 * Which documents an application needs and which are missing (D-057). A type is required once the application has
 * reached its required_from_status (its history contains that status). A person-level type is satisfied by any
 * active document of the person; an application-level type by one of this application. Missing documents are
 * shown, never blocking.
 */
final class DocumentRequirements
{
    /**
     * @return list<array{type: DocumentType, required_now: bool, present: int, missing: bool}> every active type
     */
    public static function of(Application $application): array
    {
        $reached = DB::table('application_status_events')->where('application_id', $application->id)->distinct()->pluck('to_status')->all();

        $present = Document::query()
            ->active()
            ->where('candidate_id', $application->candidate_id)
            ->where(fn($query) => $query->whereNull('application_id')->orWhere('application_id', $application->id))
            ->selectRaw('document_type_id, count(*) AS n')
            ->groupBy('document_type_id')
            ->pluck('n', 'document_type_id');

        return DocumentType::query()->orderBy('id')->get()->map(function (DocumentType $type) use ($reached, $present) {
            $requiredNow = $type->required_from_status !== null && in_array($type->required_from_status->value, $reached, true);
            $count = (int) ($present[$type->id] ?? 0);

            return ['type' => $type, 'required_now' => $requiredNow, 'present' => $count, 'missing' => $requiredNow && $count === 0];
        })->all();
    }
}
