<?php

namespace App\Modules\Exams\Console;

use App\Modules\Exams\Actions\SweepExamSessions;
use Illuminate\Console\Command;

/** Every minute: absences when a session's late window closes, attempts closed at their deadline (D-073). */
class SweepExamSessionsCommand extends Command
{
    protected $signature = 'exams:sweep';

    protected $description = 'Record exam absences and close attempts whose deadline passed';

    public function handle(SweepExamSessions $sweep): int
    {
        $done = $sweep->sweep();

        if ($done['absent'] + $done['closed'] > 0) {
            $this->info("Absent: {$done['absent']}, attempts closed: {$done['closed']}.");
        }

        return self::SUCCESS;
    }
}
