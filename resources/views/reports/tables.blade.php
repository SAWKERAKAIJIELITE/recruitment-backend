{{-- Report tables (ReportTable) as PDF, with the header of the legacy workbook. --}}
@include('reports._style')
<style>
    .band { background: #12402A; color: #fff; text-align: center; padding: 6pt 4pt; }
    .band .org { color: #C9A227; font-size: 15pt; font-weight: bold; }
    .band .dept { color: #F2E6C2; font-size: 11pt; font-weight: bold; }
    .band .title { color: #fff; font-size: 13pt; font-weight: bold; }
    .meta { background: #F2E6C2; color: #12402A; font-size: 8pt; text-align: center; padding: 3pt; margin-bottom: 8pt; }
    table.report { width: 100%; border-collapse: collapse; font-size: {{ $fontSize ?? '8.5pt' }}; }
    table.report th { background: #1B5E3A; color: #fff; border: 0.5pt solid #D9CFB4; padding: 3pt; text-align: center; }
    table.report td { background: #FBF9F3; border: 0.5pt solid #D9CFB4; padding: 2.5pt 3pt; text-align: center; }
    h3 { color: #12402A; font-size: 11pt; margin: 10pt 0 4pt 0; }
    table.totals td { border: 0.5pt solid #D9CFB4; padding: 2pt 6pt; background: #F2E6C2; color: #12402A; font-weight: bold; font-size: 9pt; }
</style>

<div class="band">
    @if ($header->logo())<img src="{{ $header->logo() }}" style="height: 40pt; float: right">@endif
    <div class="org">{{ $header->organization() }}</div>
    <div class="dept">{{ $header->department() }}</div>
    <div class="title">{{ $header->title }}</div>
</div>
<div class="meta">{{ $header->metaLine() }}</div>

@foreach ($tables as $table)
    @if (count($tables) > 1)<h3>{{ $table->title }}</h3>@endif
    <table class="report">
        <thead>
            <tr>@foreach ($table->columns as [$label])<th>{{ $label }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse ($table->rows as $row)
                <tr>@foreach ($row as $value)<td>{{ is_float($value) ? number_format($value, 1) : $value }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($table->columns) }}">{{ __('reports.no_rows', [], 'ar') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($table->totals !== [])
        <br>
        <table class="totals">
            @foreach ($table->totals as $label => $value)
                <tr><td>{{ $label }}</td><td>{{ is_float($value) ? number_format($value, 1) : $value }}</td></tr>
            @endforeach
        </table>
    @endif
@endforeach
