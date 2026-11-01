{{-- The results of many applications (D-066): no committee notes or summary, no reasons. --}}
@include('reports._style')

<div class="muted">{{ $header->organization() }}</div>
<h1>{{ $header->title }}</h1>
<div class="muted">{{ __('reports.generated', ['at' => $header->generatedAtText(), 'by' => $header->issuer->full_name], 'ar') }}</div>
<div class="muted">{{ __('reports.filters', [], 'ar') }}: {{ $filters }}</div>
<br>

<table class="grid" style="font-size: 9pt">
    <thead>
        <tr>
            <th>#</th>
            @foreach (['nomination_number', 'full_name', 'national_number', 'job_position', 'exam_result', 'interview_result', 'interview_held_on', 'scientific_grade', 'general_grade', 'overall_grade', 'status'] as $column)
                <th>{{ __("reports.columns.{$column}", [], 'ar') }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($results as $index => $result)
            @php($application = $result['application'])
            @php($interview = $result['interview'])
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $application->nomination_number }}</td>
                <td>{{ $application->candidate->full_name }}</td>
                <td>{{ $application->candidate->national_number }}</td>
                <td>{{ $application->jobPosition->name_ar }}</td>
                <td>{{ $result['exam']['label'] ?? '—' }}@if ($result['exam']['admitted_by_exception'] ?? false) ({{ __('reports.by_exception', [], 'ar') }})@endif</td>
                <td>{{ $interview['label'] ?? '—' }}</td>
                <td>{{ $interview['held_on'] ?? '—' }}</td>
                <td>{{ $interview['grades']['scientific']['label'] ?? '—' }}</td>
                <td>{{ $interview['grades']['general']['label'] ?? '—' }}</td>
                <td>{{ $interview['grades']['overall']['label'] ?? '—' }}</td>
                <td>{{ $application->current_status->labelAr() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<h2>{{ __('reports.totals', [], 'ar') }}</h2>
<table class="facts">
    @foreach ($totals as $label => $count)
        <tr><td class="label">{{ $label }}</td><td>{{ $count }}</td></tr>
    @endforeach
</table>
