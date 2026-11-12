<?php

namespace App\Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Recruitment\Http\Resources\ReferenceItemResource;
use App\Modules\Recruitment\Models\JobPosition;
use App\Support\ArabicText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The job code of a job position (الرمز الوظيفي; D-098): the Contracts office's, printed on the contracts. */
class JobCodeController extends Controller
{
    /**
     * Set a job position's job code
     *
     * `job_code`: up to 50 characters (Arabic-Indic digits are stored as ASCII), or null to clear it. Shown with the
     * job positions list, on the contracts of that position, on the contract form and in ملف التثبيت. Audited.
     */
    public function update(Request $request, int $id): ReferenceItemResource
    {
        $data = $request->validate(['job_code' => ['present', 'nullable', 'string', 'max:50']]);
        $code = $data['job_code'] === null ? null : trim(ArabicText::digitsToAscii($data['job_code']));
        $code = $code === '' ? null : $code;

        $position = DB::transaction(function () use ($id, $code, $request) {
            $position = JobPosition::withTrashed()->lockForUpdate()->findOrFail($id);
            $before = $position->job_code;
            $position->forceFill(['job_code' => $code])->save();
            AuditTrail::record('contract.job_code_set', $request->user(), ['job_position_id' => $position->id, 'from' => $before, 'to' => $code]);

            return $position;
        });

        return new ReferenceItemResource($position);
    }
}
