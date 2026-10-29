<?php

namespace App\Modules\Documents\Database\Seeders;

use App\Modules\Documents\Actions\ManageDocuments;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * LOCAL DEVELOPMENT ONLY. Tiny placeholder PDFs through the real upload action, so the documents screens show both
 * complete and incomplete files: the first half of the demo applications get the ID card and certificates, and half
 * of those with a written contract get the contract. Needs RecruitmentDemoSeeder and DocumentTypesSeeder. Runs once.
 */
class DocumentsDemoSeeder extends Seeder
{
    public function __construct(private readonly ManageDocuments $documents)
    {
    }

    public function run(): void
    {
        if (Document::query()->exists()) {
            $this->command?->info('Documents already exist: demo skipped.');

            return;
        }

        $staff = StaffUser::query()->where('username', 'staff')->firstOrFail();
        $types = DocumentType::query()->get()->keyBy('name_ar');
        $applications = Application::query()->with('candidate')->orderBy('created_at')->get();
        $path = tempnam(sys_get_temp_dir(), 'demo-doc-') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        try {
            foreach ($applications->take(intdiv($applications->count(), 2)) as $application) {
                foreach (['صورة البطاقة الشخصية', 'الشهادات العلمية'] as $name) {
                    if (isset($types[$name]) && ! Document::query()->where('candidate_id', $application->candidate_id)->where('document_type_id', $types[$name]->id)->exists()) {
                        $this->documents->upload($this->file($path, $name), $types[$name], $application->candidate, $application, null, $staff);
                    }
                }
            }

            $contracts = $applications->filter(fn(Application $application) => in_array($application->current_status, [ApplicationStatus::ContractWritten, ApplicationStatus::ContractEnded], true));

            foreach ($contracts->values() as $index => $application) {
                if ($index % 2 === 0 && isset($types['العقد'])) {
                    $this->documents->upload($this->file($path, 'العقد'), $types['العقد'], $application->candidate, $application, 'العقد الأول', $staff);
                }
            }
        } finally {
            @unlink($path);
        }

        $this->command?->info('Documents demo: ' . Document::query()->count() . ' placeholder files.');
    }

    private function file(string $path, string $name): UploadedFile
    {
        return new UploadedFile($path, "{$name}.pdf", 'application/pdf', null, true);
    }
}
