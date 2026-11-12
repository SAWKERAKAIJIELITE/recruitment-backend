<?php

/*
 * The whole recruitment lifecycle against a deployed server, through HTTPS only, the way the offices work
 * (docs/deployment.md, step 17). STAGING ONLY: it adds users, candidates, exams, interviews and contracts.
 *
 *   sudo php tools/live-lifecycle.php <base-url> <app-dir> <admin-username> <admin-password> [<ca-certificate>]
 *   sudo php tools/live-lifecycle.php https://hr.example.local /var/www/recruitment it.admin '…' /etc/ssl/recruitment/recruitment-ca.crt
 *
 * Needs: a fresh installation (deployment steps 1 to 12, after staff:create-admin, nothing else), the seeded
 * catalogue, the scheduler's cron running, and this machine inside EXAMS_CANDIDATE_NETWORKS (the candidates log in
 * from here). Run as root: the administrator's device is approved with `php artisan staff:approve-device`, as the
 * owner of <app-dir>. It waits for real time to pass (the exam sessions, the late window, the sitting): about six
 * minutes. It prints one line a check and exits 1 when one failed.
 *
 * 1. the administrator's device registered, approved from the shell, a signed login (token ends at midnight);
 * 2. one user per office added by the administrator, each with a device the administrator approves;
 * 3. the vacancies file, four candidates imported from the legacy workbook, started;
 * 4. a subject, its questions, a published plan, a session booked from the queue; three candidates sit it at the
 *    venue (A and D pass, B fails), C does not come and the scheduler (cron) marks C absent;
 * 5. B admitted by exception, C excused and booked into a second session, which C passes;
 * 6. a sitting: A and C pass, B absent then excused, rebooked and passed, D fails and is closed by Recruitment;
 * 7. a unit and a job code, three contracts, the printed form, ملف التثبيت, a renewal (A) and an end (C);
 * 8. the dashboard's totals, the reports (Excel and PDF), the audit log, C nominated again.
 */

$app = $argv[2] ?? null;

if (count($argv) < 5 || ! is_file("{$app}/vendor/autoload.php")) {
    fwrite(STDERR, "usage: php live-lifecycle.php <base-url> <app-dir> <admin-username> <admin-password> [<ca-certificate>]\n");
    exit(2);
}

require "{$app}/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

[, $base, , $adminUser, $adminPassword] = $argv;
$caFile = $argv[5] ?? null;
$api = rtrim($base, '/') . '/api/v1';
$zone = new DateTimeZone(getenv('ORG_TIMEZONE') ?: 'Asia/Damascus');   // REPORTS_TIMEZONE of the server
$out = sys_get_temp_dir() . '/live-lifecycle-' . gmdate('Ymd-His');
mkdir($out, 0700, true);
$failures = 0;
$checks = 0;

const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

function req(string $method, string $url, ?string $token = null, ?array $body = null, bool $multipart = false): array
{
    global $caFile;
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    $token && $headers[] = "Authorization: Bearer {$token}";

    if ($body !== null && ! $multipart) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    $responseHeaders = [];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 180,
        CURLOPT_NOPROXY => '*',   // straight to the server, whatever proxy the shell has
        CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        },
    ]);
    $caFile && curl_setopt($curl, CURLOPT_CAINFO, $caFile);
    $body !== null && curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $raw === false && fwrite(STDERR, '  curl: ' . curl_error($curl) . "\n");
    curl_close($curl);

    return ['status' => $status, 'json' => json_decode((string) $raw, true), 'raw' => (string) $raw, 'headers' => $responseHeaders];
}

function check(bool $ok, string $what, mixed $detail = null): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($ok || $detail === null ? '' : ' — ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    $ok || $failures++;
}

/** A step the next ones build on: its status is checked, and the run stops when it is wrong. */
function expect(array $response, int $status, string $what): array
{
    check($response['status'] === $status, "{$what} → {$status}", $response['status'] === $status ? null : [$response['status'], mb_substr($response['raw'], 0, 600)]);

    if ($response['status'] !== $status) {
        echo "  stopped: the next steps build on this one\n";
        exit(1);
    }

    return $response['json'] ?? [];
}

function artisan(string $app, string $command): string
{
    $owner = posix_getpwuid(fileowner($app))['name'];

    return (string) shell_exec('runuser -u ' . escapeshellarg($owner) . ' -- bash -c ' . escapeshellarg("cd {$app} && php artisan {$command}") . ' 2>&1');
}

function waitUntil(int $timestamp, string $why): void
{
    if (($seconds = $timestamp - time()) > 0) {
        echo "  …    waiting {$seconds}s ({$why})\n";
        sleep($seconds);
    }
}

/** A device: a P-256 key pair, registered; returns [id, fingerprint, private key]. */
function device(string $api, string $label, int $index): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $public = openssl_pkey_get_details($key)['key'];
    $json = expect(req('POST', "{$api}/staff/devices/register", null, ['public_key' => $public, 'label' => $label, 'mac_address' => sprintf('02:10:00:00:00:%02x', $index), 'os_info' => 'Windows 11']), 201, "register the device «{$label}»");

    return [$json['device']['id'], $json['device']['fingerprint'], $key];
}

/** The device protocol: a challenge, signed with the device's key (docs/code-documentation.md 7.6). */
function login(string $api, array $device, string $username, string $password): array
{
    [$id, , $key] = $device;
    $challenge = expect(req('POST', "{$api}/staff/auth/challenge", null, ['device_id' => $id]), 200, "challenge for {$username}");
    $payload = implode("\n", ['recruitment-staff-login-v1', $challenge['challenge_id'], $challenge['challenge'], $id, $username]);
    openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);

    return expect(req('POST', "{$api}/staff/auth/login", null, ['device_id' => $id, 'challenge_id' => $challenge['challenge_id'], 'signature' => base64_encode($signature), 'username' => $username, 'password' => $password]), 200, "{$username} logs in with a signature");
}

function upload(Spreadsheet $book, string $name): CURLFile
{
    global $out;
    $path = "{$out}/" . uniqid('upload-') . '.xlsx';
    (new Xlsx($book))->save($path);

    return new CURLFile($path, XLSX, $name);
}

/** The vacancies file: a title, the header on row 3, a row per job position. */
function vacanciesFile(array $positions): CURLFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setCellValue('A1', 'وزارة الأوقاف');
    $sheet->fromArray(['المسمى الوظيفي', 'عدد الشواغر'], null, 'A3');

    foreach (array_values($positions) as $index => $position) {
        $sheet->setCellValue('A' . (4 + $index), $position['name_ar']);
        $sheet->setCellValue('B' . (4 + $index), 10);
    }

    return upload($book, 'الشواغر.xlsx');
}

/** The legacy candidates workbook: sheet «بيانات المرشحين», three title rows, the header on row 5 (columns B to W). */
function candidatesFile(array $people): CURLFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->setTitle('بيانات المرشحين');
    $sheet->setRightToLeft(true);
    $sheet->setCellValue('B1', 'وزارة الأوقاف');
    $sheet->setCellValue('B2', 'قسم التوظيف');
    $sheet->setCellValue('B3', 'قاعدة بيانات المرشحين');
    $header = ['B' => 'الجهة المرشحة', 'C' => 'المسمى الوظيفي', 'D' => 'الفئة', 'E' => 'الرقم الذاتي', 'F' => 'الاسم الثلاثي', 'G' => 'اسم الأم',
        'H' => 'مكان الولادة', 'I' => 'تاريخ الولادة', 'J' => 'الرقم الوطني', 'K' => 'الحالة الاجتماعية', 'L' => 'الحالة الصحية/بالتفصيل',
        'M' => 'محافظة الإقامة', 'N' => 'مكان الإقامة الحالية', 'O' => 'المؤهل العلمي', 'P' => 'الاختصاص', 'Q' => 'عدد السنين الدراسية',
        'R' => 'عدد الأجزاء المحفوظة من القرآن', 'S' => 'هل تعمل مع جهة حكومية أخرى', 'T' => 'الالتزام بالمبيت والدوام بنسبة 50%',
        'U' => 'التفرغ الكامل للعمل', 'V' => 'رقم الهاتف', 'W' => 'ملاحظات'];

    foreach ($header as $column => $title) {
        $sheet->setCellValue("{$column}5", $title);
    }

    $line = 6;

    foreach ($people as $number => $name) {
        $values = ['B' => 'مديرية الأوقاف', 'C' => 'موجه تربوي', 'D' => 'متعاقد جديد', 'F' => $name, 'G' => 'فاطمة حسن', 'H' => 'دمشق',
            'J' => $number, 'K' => 'أعزب', 'L' => 'سليم', 'M' => 'دمشق', 'N' => 'المزة', 'O' => 'جامعي', 'P' => 'شريعة', 'S' => 'لا',
            'T' => 'نعم', 'U' => 'نعم', 'V' => '0912345678'];

        foreach ($values as $column => $value) {
            $sheet->setCellValueExplicit("{$column}{$line}", $value, DataType::TYPE_STRING);   // text: leading zeros kept
        }

        $sheet->setCellValue("I{$line}", ExcelDate::PHPToExcel(new DateTimeImmutable('1998-03-01')));
        $sheet->getStyle("I{$line}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->setCellValue("Q{$line}", 12);
        $sheet->setCellValue("R{$line}", 5);
        $line++;
    }

    return upload($book, 'المرشحون.xlsx');
}

$iso = fn(int $timestamp) => gmdate('Y-m-d\TH:i:s\Z', $timestamp);
$status = fn(string $token, string $id) => req('GET', "{$api}/staff/applications/{$id}", $token)['json']['data']['status'] ?? null;
$today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');

echo "1. The administrator's device: registered, approved from the shell, then a signed login\n";
check(req('GET', "{$api}/ping")['status'] === 200, "the server answers at {$api}");
$adminDevice = device($api, 'جهاز مسؤول النظام', 1);
$output = artisan($app, "staff:approve-device {$adminDevice[0]} " . escapeshellarg($adminUser) . ' --fingerprint=' . escapeshellarg($adminDevice[1]));
check(str_contains($output, 'approved'), 'staff:approve-device', trim($output));
$session = login($api, $adminDevice, $adminUser, $adminPassword);
$admin = $session['token'];
$midnight = (new DateTimeImmutable('tomorrow', $zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
check(str_starts_with($session['expires_at'] ?? '', $midnight), 'the token ends at the next midnight of ' . $zone->getName(), $session['expires_at'] ?? null);
$anonymous = req('GET', "{$api}/staff/auth/me");
check($anonymous['status'] === 401 && preg_match('/\p{Arabic}/u', $anonymous['json']['message'] ?? ''), 'without a token: 401, in Arabic', $anonymous['json'] ?? null);

echo "2. The administrator adds one user per office; each registers a device the administrator approves\n";
$password = 'Office!Pass2026';
$users = $ids = [];
$index = 2;

foreach (['recruitment' => 'hr.officer', 'exams' => 'exam.officer', 'interviews' => 'interview.officer', 'contracts' => 'contract.officer', 'general_manager' => 'the.manager'] as $role => $username) {
    $user = expect(req('POST', "{$api}/staff/new", $admin, [
        'username' => $username, 'first_name' => 'موظف', 'last_name' => 'التجربة', 'personal_number' => (string) (700000 + $index), 'is_active' => true,
        'role' => $role, 'password' => $password, 'password_confirmation' => $password,
    ]), 201, "add {$username} ({$role})");
    $device = device($api, "جهاز {$username}", $index++);
    expect(req('POST', "{$api}/staff/devices/{$device[0]}/approve", $admin, ['staff_user_id' => $user['user']['id'], 'fingerprint' => $device[1]]), 200, "approve {$username}'s device");
    $ids[$role] = $user['user']['id'];
    $users[$role] = login($api, $device, $username, $password)['token'];
}

[$hr, $exams, $interviews, $contracts, $manager] = [$users['recruitment'], $users['exams'], $users['interviews'], $users['contracts'], $users['general_manager']];
check(req('GET', "{$api}/staff/exams/queue", $interviews)['status'] === 403, 'the interviews officer cannot reach the exams');
check(req('POST', "{$api}/staff/new", $hr, ['username' => 'not.allowed'])['status'] === 403, 'only the administrator adds users');

echo "3. The administrator uploads the vacancies; Recruitment imports four candidates and starts them\n";
$positions = req('GET', "{$api}/staff/reference/job-positions", $hr)['json']['data'];
$position = array_values(array_filter($positions, fn(array $item) => $item['name_ar'] === 'موجه تربوي'))[0];
$quarter = (new DateTimeImmutable('first day of this month', $zone))->format('Y-m-d');
expect(req('POST', "{$api}/staff/vacancy-plans", $admin, ['file' => vacanciesFile($positions), 'starts_on' => $quarter], true), 201, "upload the vacancies (from {$quarter})");
$people = ['01111111111' => 'أحمد محمد علي', '02222222222' => 'سارة خالد عمر', '03333333333' => 'محمود سعيد حسن', '04444444444' => 'ليلى أحمد ناصر'];
$batch = expect(req('POST', "{$api}/staff/imports", $hr, ['file' => candidatesFile($people)], true), 201, 'upload the candidates workbook')['data'];
check(($batch['counts']['valid'] ?? null) === 4, 'four valid rows', $batch['counts'] ?? null);
expect(req('POST', "{$api}/staff/imports/{$batch['id']}/approve", $hr, ['revision' => $batch['revision']]), 200, 'approve the batch');
$applications = [];

foreach (req('GET', "{$api}/staff/applications?per_page=50", $hr)['json']['data'] as $row) {
    $applications[$row['candidate']['national_number']] = $row['id'];
}

[$a, $b, $c, $d] = [$applications['01111111111'], $applications['02222222222'], $applications['03333333333'], $applications['04444444444']];
expect(req('POST', "{$api}/staff/applications/start", $hr, ['filters' => ['import_batch_id' => $batch['id']]]), 200, 'start the batch');
check(array_map(fn($id) => $status($hr, $id), [$a, $b, $c, $d]) === array_fill(0, 4, 'awaiting_exam'), 'all four await their exam');
check((req('GET', "{$api}/staff/notifications/unread-count", $exams)['json']['data']['unread'] ?? 0) > 0, 'the exams officer is told');

echo "4. The exams: a subject, a question of each difficulty, a published plan, a session from the queue\n";
$subjectId = expect(req('POST', "{$api}/staff/exam-subjects", $exams, ['name_ar' => 'مادة التجربة الحية ' . gmdate('His')]), 201, 'add a subject')['data']['id'];

foreach (['easy', 'medium', 'hard'] as $difficulty) {
    expect(req('POST', "{$api}/staff/exam-questions", $exams, ['subject_id' => $subjectId, 'difficulty' => $difficulty, 'prompt' => "سؤال {$difficulty}: ما حكم كذا؟", 'options' => ['الجواب الصحيح', 'جواب خاطئ', 'جواب آخر'], 'correct_option' => 0]), 201, "a {$difficulty} question");
}

$draft = expect(req('POST', "{$api}/staff/exam-plans", $exams, ['job_position_id' => $position['id']]), 201, 'a draft plan of «موجه تربوي»')['data']['id'];
expect(req('PUT', "{$api}/staff/exam-plans/{$draft}", $exams, ['duration_minutes' => 30, 'notes' => null, 'subjects' => [['subject_id' => $subjectId, 'questions' => ['easy' => 1, 'medium' => 1, 'hard' => 1]]]]), 200, 'its content');
$planVersion = expect(req('POST', "{$api}/staff/exam-plans/{$draft}/publish", $exams), 200, 'publish it')['data']['id'];
$examStart = time() + 75;
$examSession = expect(req('POST', "{$api}/staff/exam-sessions", $exams, ['plan_version_id' => $planVersion, 'starts_at' => $iso($examStart), 'location' => 'قاعة الحاسوب', 'password' => 'qalam-2026', 'late_minutes' => 1]), 201, 'an exam session (late after 1 minute)')['data']['id'];
$queue = array_column(req('GET', "{$api}/staff/exams/queue", $exams)['json']['data'], 'id');
expect(req('POST', "{$api}/staff/exam-sessions/{$examSession}/bookings", $exams, ['application_ids' => $queue]), 201, 'book the queue (' . count($queue) . ')');

$sit = function (string $number, int $right) use ($api) {
    $token = expect(req('POST', "{$api}/candidate/auth/login", null, ['national_number' => $number, 'password' => 'qalam-2026']), 200, "candidate {$number} logs in at the venue")['token'];
    expect(req('POST', "{$api}/candidate/exam/start", $token), 200, "{$number} starts");

    foreach (req('GET', "{$api}/candidate/exam/questions", $token)['json']['data'] as $i => $question) {
        req('PUT', "{$api}/candidate/exam/answers/{$question['position']}", $token, ['option' => $i < $right ? 0 : 1]);
    }

    expect(req('POST', "{$api}/candidate/exam/submit", $token), 200, "{$number} hands in ({$right} of 3 right)");
};

waitUntil($examStart + 2, 'the exam starts');
$sit('01111111111', 3);
$sit('02222222222', 1);
$sit('04444444444', 3);
$decided = [$status($hr, $a), $status($hr, $b), $status($hr, $d)];
check($decided === ['awaiting_interview', 'exam_failed', 'awaiting_interview'], 'decided at hand-in: A and D passed, B failed', $decided);
waitUntil($examStart + 61, 'the late window closes');
$deadline = time() + 150;

while ($status($hr, $c) !== 'exam_absent' && time() < $deadline) {
    sleep(5);
}

check($status($hr, $c) === 'exam_absent', 'C marked absent by the scheduler (cron), ' . (time() - $examStart - 60) . 's after the late window', $status($hr, $c));

echo "5. The exams officer's decisions: an exception for B, an excuse for C with a second round\n";
expect(req('POST', "{$api}/staff/applications/{$b}/exam-exception", $exams, ['reason' => 'قرار لجنة الاختبارات', 'decision_number' => 'ق/2026/7']), 200, 'B admitted by exception');
$secondStart = time() + 70;
$second = expect(req('POST', "{$api}/staff/exam-sessions", $exams, ['plan_version_id' => $planVersion, 'starts_at' => $iso($secondStart), 'location' => 'قاعة الحاسوب', 'password' => 'qalam-2026', 'late_minutes' => 1]), 201, 'a second session')['data']['id'];
expect(req('POST', "{$api}/staff/applications/{$c}/exam-excuse", $exams, ['reason' => 'تقرير طبي', 'session_id' => $second, 'decision_number' => 'ق/2026/8']), 200, 'C excused, booked into it');
waitUntil($secondStart + 2, 'the second exam starts');
$sit('03333333333', 3);
check($status($hr, $c) === 'awaiting_interview', 'C passed the second round');

echo "6. The interviews: a sitting, the queue booked, the results; B absent then excused and passed\n";
$sittingStart = time() + 20;
$sitting = expect(req('POST', "{$api}/staff/interview-sittings", $interviews, [
    'starts_at' => $iso($sittingStart), 'ends_at' => $iso($sittingStart + 7200), 'location' => 'قاعة المقابلات', 'committee_name' => 'لجنة التجربة الحية',
    'members' => [['name' => 'الشيخ محمد السالم', 'capacity' => 'chair'], ['staff_user_id' => $ids['interviews'], 'capacity' => 'secretary']],
]), 201, 'a sitting')['data']['id'];
check((req('GET', "{$api}/staff/badges", $interviews)['json']['data']['interviews']['to_book'] ?? null) === 4, 'the sidebar: four to book');
expect(req('POST', "{$api}/staff/interview-sittings/{$sitting}/bookings", $interviews, ['application_ids' => [$a, $b, $c, $d], 'first_at' => $iso($sittingStart), 'minutes_between' => 5]), 201, 'book four, five minutes apart');
waitUntil($sittingStart + 2, 'the sitting starts');
$interviewOf = fn(string $id) => array_values(array_filter(req('GET', "{$api}/staff/applications/{$id}/interviews", $interviews)['json']['data'], fn(array $row) => $row['status'] === 'scheduled'))[0]['id'];
$grades = ['scientific_grade' => 'very_good', 'general_grade' => 'good', 'overall_grade' => 'very_good'];
$heldNow = fn() => ['held_at' => $iso(time())];   // by default an interview is held at its booked time, still to come for B to D

foreach ([[$a, 'passed'], [$b, 'absent'], [$c, 'passed'], [$d, 'failed']] as [$id, $result]) {
    expect(req('POST', "{$api}/staff/interviews/{$interviewOf($id)}/result", $interviews, ['result' => $result] + $heldNow() + ($result === 'absent' ? [] : $grades)), 200, "record {$result}");
}

expect(req('POST', "{$api}/staff/applications/{$b}/interview-excuse", $interviews, ['reason' => 'ظرف طارئ', 'decision_number' => 'ق/2026/9', 'appointment' => ['sitting_id' => $sitting]]), 201, 'B excused and rebooked');
expect(req('POST', "{$api}/staff/interviews/{$interviewOf($b)}/result", $interviews, ['result' => 'passed'] + $heldNow() + $grades), 200, 'B passes');
check(req('POST', "{$api}/staff/applications/{$d}/close", $contracts, ['reason' => 'راسب بالمقابلة'])['status'] === 403, 'the Contracts office cannot close a file');
expect(req('POST', "{$api}/staff/applications/{$d}/close", $hr, ['reason' => 'راسب بالمقابلة']), 200, 'Recruitment closes D');

echo "7. The contracts: a unit and a job code, three first contracts, the form, ملف التثبيت, a renewal, an end\n";
$unit = expect(req('POST', "{$api}/staff/reference/units", $contracts, ['name_ar' => 'وحدة التجربة الحية ' . gmdate('His')]), 201, 'a unit')['item']['id'];
expect(req('PUT', "{$api}/staff/job-positions/{$position['id']}/job-code", $contracts, ['job_code' => 'JC-101']), 200, 'a job code');
$type = req('GET', "{$api}/staff/reference/contract-types", $contracts)['json']['data'][0]['id'];
$yearOn = (new DateTimeImmutable($today))->modify('+1 year -1 day')->format('Y-m-d');
$write = fn(string $id, string $reference, array $more = []) => req('POST', "{$api}/staff/applications/{$id}/contracts", $contracts, $more + ['contract_type_id' => $type, 'reference_number' => $reference, 'starts_on' => $today, 'ends_on' => $yearOn]);
$aContract = expect($write($a, 'ع/تجربة/1', ['unit_id' => $unit, 'salary_usd' => 350, 'certificate_type' => 'bachelor_4', 'university' => 'جامعة دمشق', 'gpa' => 72.5, 'wives_count' => 1, 'children_count' => 2]), 201, "A's first contract")['data']['id'];
expect($write($b, 'ع/تجربة/2'), 201, "B's");
$cContract = expect($write($c, 'ع/تجربة/3'), 201, "C's")['data']['id'];
$form = req('GET', "{$api}/staff/contracts/{$aContract}/form", $contracts);
check($form['status'] === 200 && str_starts_with($form['raw'], '%PDF') && str_contains($form['headers']['content-disposition'] ?? '', "UTF-8''"), 'the contract form, a PDF with an Arabic file name', [$form['status'], $form['headers']['content-type'] ?? null]);
file_put_contents("{$out}/contract-form.pdf", $form['raw']);
$file = req('GET', "{$api}/staff/contracts/confirmation-file?written_from={$today}", $contracts);
check($file['status'] === 200 && str_starts_with($file['raw'], 'PK'), 'ملف التثبيت (Excel)', $file['status']);
file_put_contents("{$out}/confirmation-file.xlsx", $file['raw']);
expect(req('POST', "{$api}/staff/contracts/{$aContract}/renew", $contracts, ['contract_type_id' => $type, 'reference_number' => 'ت/تجربة/1', 'ends_on' => (new DateTimeImmutable($today))->modify('+2 years')->format('Y-m-d')]), 201, "renew A's");
expect(req('POST', "{$api}/staff/contracts/{$cContract}/end", $contracts, ['ended_on' => $today, 'reason' => 'استقالة']), 200, "end C's");
$final = array_map(fn($id) => $status($hr, $id), [$a, $b, $c, $d]);
check($final === ['contract_written', 'contract_written', 'contract_ended', 'closed'], 'the four files end where they should', $final);

echo "8. Reports, the audit log, the person nominated again\n";
$totals = req('GET', "{$api}/staff/reports/summary?group_by=day", $manager)['json']['data']['totals'] ?? [];
check(($totals['contracts'] ?? null) === ['written' => 3, 'renewals' => 1], 'the dashboard: 3 contracts, 1 renewal', $totals['contracts'] ?? null);
check([$totals['exams']['passed'] ?? null, $totals['exams']['failed'] ?? null, $totals['exams']['passed_by_exception'] ?? null, $totals['exams']['absent_excused'] ?? null] === [3, 1, 1, 1], 'the dashboard: the exams (3 passed, 1 failed, 1 exception, 1 excused)', $totals['exams'] ?? null);
check([$totals['interviews']['passed'] ?? null, $totals['interviews']['failed'] ?? null, $totals['interviews']['absent_excused'] ?? null] === [3, 1, 1], 'the dashboard: the interviews (3 passed, 1 failed, 1 excused)', $totals['interviews'] ?? null);

foreach (['reports/workbook' => [$manager, XLSX], 'reports/contracts?format=pdf' => [$manager, 'application/pdf'], "applications/{$a}/results-statement" => [$manager, 'application/pdf'], 'reports/conscription-list' => [$hr, XLSX]] as $path => [$token, $type]) {
    $response = req('GET', "{$api}/staff/{$path}", $token);
    check($response['status'] === 200 && str_starts_with($response['headers']['content-type'] ?? '', $type), "download {$path}", [$response['status'], $response['headers']['content-type'] ?? null, mb_substr($response['raw'], 0, 200)]);
    $response['status'] === 200 && file_put_contents("{$out}/" . preg_replace('/\W+/', '-', strtok($path, '?')) . ($type === XLSX ? '.xlsx' : '.pdf'), $response['raw']);
}

$events = array_column(req('GET', "{$api}/staff/audit-events?per_page=200", $admin)['json']['data'] ?? [], 'event_label', 'event');
$expected = ['auth.staff_login', 'device.approved', 'import.approved', 'exam.exception_approved', 'exam.excuse_accepted', 'interview.recorded', 'interview.excuse_accepted', 'contract.written', 'contract.renewed', 'contract.ended', 'contract.form_printed'];
check(array_diff($expected, array_keys($events)) === [], 'the audit log has every office\'s decisions, in Arabic (' . ($events['contract.ended'] ?? '?') . ')', array_values(array_diff($expected, array_keys($events))));
$again = req('POST', "{$api}/staff/applications", $hr, ['candidate' => ['national_number' => '03333333333', 'full_name' => 'محمود سعيد حسن']]);
check($again['status'] === 422 && ! isset($again['json']['errors']['candidate.national_number']), 'C may be nominated again (only the missing fields are refused)', array_keys($again['json']['errors'] ?? []));

echo "\n{$checks} checks, {$failures} failed. The downloaded files: {$out}\n";
exit($failures === 0 ? 0 : 1);
