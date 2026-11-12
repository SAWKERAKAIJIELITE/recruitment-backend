<?php

namespace App\Modules\Reports\Enums;

/** The reports GET /reports/{report} exports (D-066, D-094): the path parameter, documented with its values. */
enum ReportName: string
{
    case Applications = 'applications';
    case Exams = 'exams';
    case ExamsPassed = 'exams-passed';
    case ExamsFailed = 'exams-failed';
    case ExamsAbsent = 'exams-absent';
    case Interviews = 'interviews';
    case InterviewsPassed = 'interviews-passed';
    case InterviewsFailed = 'interviews-failed';
    case InterviewsAbsent = 'interviews-absent';
    case Contracts = 'contracts';
    case Dashboard = 'dashboard';
}
