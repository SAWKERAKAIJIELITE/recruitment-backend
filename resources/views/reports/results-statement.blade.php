{{-- One candidate's results (D-066): no committee notes or summary, no reasons. --}}
@include('reports._style')
@php($application = $result['application'])

<div class="muted">{{ $header->organization() }}</div>
<h1>{{ $header->title }}</h1>
<div class="muted">{{ __('reports.generated', ['at' => $header->generatedAtText(), 'by' => $header->issuer->full_name], 'ar') }}</div>

<h2>{{ __('reports.candidate', [], 'ar') }}</h2>
<table class="facts">
    <tr><td class="label">{{ __('reports.columns.full_name', [], 'ar') }}</td><td>{{ $application->candidate->full_name }}</td></tr>
    <tr><td class="label">{{ __('reports.columns.national_number', [], 'ar') }}</td><td>{{ $application->candidate->national_number }}</td></tr>
    <tr><td class="label">{{ __('reports.columns.nomination_number', [], 'ar') }}</td><td>{{ $application->nomination_number }}</td></tr>
    <tr><td class="label">{{ __('reports.columns.job_position', [], 'ar') }}</td><td>{{ $application->jobPosition->name_ar }}</td></tr>
    <tr><td class="label">{{ __('reports.columns.nominating_entity', [], 'ar') }}</td><td>{{ $application->nominatingEntity->name_ar }}</td></tr>
</table>

<h2>{{ __('reports.exam', [], 'ar') }}</h2>
@if ($result['exam'] === null)
    <p>{{ __('reports.no_exam_result', [], 'ar') }}</p>
@else
    <table class="facts">
        <tr><td class="label">{{ __('reports.columns.exam_result', [], 'ar') }}</td><td>{{ $result['exam']['label'] }}</td></tr>
        <tr><td class="label">{{ __('reports.date', [], 'ar') }}</td><td>{{ $result['exam']['decided_on'] }}</td></tr>
        @if ($result['exam']['admitted_by_exception'])
            <tr><td class="label">{{ __('reports.columns.admitted_by_exception', [], 'ar') }}</td><td>{{ __('reports.yes', [], 'ar') }}</td></tr>
        @endif
    </table>
@endif

<h2>{{ __('reports.interview', [], 'ar') }}</h2>
@if ($result['interviews'] === [])
    <p>{{ __('reports.no_interview_result', [], 'ar') }}</p>
@else
    <table class="grid">
        <tr>
            <th>{{ __('reports.round', [], 'ar') }}</th>
            <th>{{ __('reports.columns.interview_held_on', [], 'ar') }}</th>
            <th>{{ __('reports.columns.interview_result', [], 'ar') }}</th>
            <th>{{ __('reports.columns.scientific_grade', [], 'ar') }}</th>
            <th>{{ __('reports.columns.general_grade', [], 'ar') }}</th>
            <th>{{ __('reports.columns.overall_grade', [], 'ar') }}</th>
        </tr>
        @foreach ($result['interviews'] as $round)
            <tr>
                <td>{{ $round['round'] }}</td>
                <td>{{ $round['held_on'] }}</td>
                <td>{{ $round['label'] }}</td>
                <td>{{ $round['grades']['scientific']['label'] ?? '—' }}</td>
                <td>{{ $round['grades']['general']['label'] ?? '—' }}</td>
                <td>{{ $round['grades']['overall']['label'] ?? '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif
