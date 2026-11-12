{{-- The contract form (تعاقد عمل), filled: the owner's template (D-098). Right to left: a row's first cell is on the right. --}}
<style>
    body { font-family: xbriyaz; font-size: 10.5pt; color: #111; }
    .frame { border: 2.2pt double #222; padding: 8pt 10pt 6pt 10pt; }
    table { border-collapse: collapse; }
    .head td { vertical-align: top; }
    .state { font-size: 15pt; font-weight: bold; }
    .ministry { font-size: 15pt; font-weight: bold; padding-right: 30pt; }
    .en { font-family: dejavuserif; font-size: 12.5pt; font-weight: bold; text-align: center; direction: ltr; }
    .unit { font-size: 11pt; font-weight: bold; padding: 2pt 0 4pt 0; border-bottom: 1pt solid #222; }
    .title { font-size: 15pt; font-weight: bold; text-align: center; }
    .numbers td { padding: 6pt 0 6pt 0; font-size: 10.5pt; }
    table.party { width: 100%; }
    table.party th { background: #1E7B3E; color: #fff; font-weight: normal; padding: 3pt; border: 0.7pt solid #222; text-align: center; }
    table.party td { border: 0.7pt solid #222; padding: 4pt 5pt; height: 15pt; }
    .photo { text-align: center; color: #999; font-size: 9pt; vertical-align: middle; }
    table.grid { width: 100%; }
    table.grid td { border: 0.7pt solid #222; padding: 3.2pt 5pt; height: 15pt; }
    table.grid td.label { background: #D9D9D9; width: 52%; }
    .period { padding: 7pt 0 5pt 0; font-size: 10pt; }
    .conditions { background: #F0F0F0; padding: 5pt 9pt 5pt 9pt; font-size: 9.6pt; }
    .conditions .heading { color: #C00000; font-weight: bold; text-decoration: underline; padding-bottom: 3pt; }
    .conditions div.line { padding: 2.4pt 0; }
    .note { color: #C00000; padding-top: 3pt; }
    table.signatures td { border: 0.7pt dashed #888; height: 92pt; vertical-align: top; text-align: center; padding-top: 6pt; font-size: 11.5pt; }
    .dated { border: 0.7pt dashed #888; padding: 5pt; text-align: center; font-size: 11pt; margin-top: 6pt; }
    .value { font-weight: bold; }
</style>

<div class="frame">
    <table class="head" width="100%">
        <tr>
            <td width="36%">
                <div class="state">الجمهورية العربية السورية</div>
                <div class="ministry">وزارة الدفاع</div>
            </td>
            <td width="28%" style="text-align: center"><img src="{{ $emblem }}" style="height: 70pt"></td>
            <td width="36%" style="text-align: left">
                <div class="en">Syrian Arab Republic</div>
                <div class="en">Ministry of Defense</div>
            </td>
        </tr>
    </table>

    <div class="unit">الجهة: <span class="value">{{ $issuer }}</span></div>

    <table class="numbers" width="100%">
        <tr>
            <td width="27%">الرقم الذاتي: <span class="value">{{ $employeeNumber }}</span></td>
            <td width="27%">رقم الاستمارة: <span class="value">{{ $formNumber }}</span></td>
            <td width="26%" class="title">(تعاقد عمل)</td>
            <td width="20%"></td>
        </tr>
    </table>

    <table width="100%">
        <tr>
            <td width="37%" style="vertical-align: top">
                <table class="party">
                    <tr><th>الفريق الأول</th></tr>
                    <tr><td>وزارة الدفاع /السوية التنظيمية الأولى</td></tr>
                    <tr><td>&nbsp;</td></tr>
                </table>
            </td>
            <td width="3%"></td>
            <td width="37%" style="vertical-align: top">
                <table class="party">
                    <tr><th>الفريق الثاني</th></tr>
                    <tr><td>الاسم الثلاثي: <span class="value">{{ $fullName }}</span></td></tr>
                    <tr><td>اسم ونسبة الأم: <span class="value">{{ $motherName }}</span></td></tr>
                </table>
            </td>
            <td width="23%" class="photo">ضع الصورة هنا</td>
        </tr>
    </table>

    <br>
    <table width="100%">
        <tr>
            <td width="48.5%" style="vertical-align: top">
                <table class="grid">
                    @foreach ($right as [$label, $value])
                        <tr><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
                    @endforeach
                </table>
            </td>
            <td width="3%"></td>
            <td width="48.5%" style="vertical-align: top">
                <table class="grid">
                    @foreach ($left as [$label, $value])
                        <tr><td class="label">{{ $label }}</td><td class="value">{{ $value }}</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <div class="period">
        مدة العقد تبدأ من: <span class="value">{{ $startsOn }}</span>م؛
        وتنتهي حتى: <span class="value">{{ $endsOn ?? '—' }}</span>@if ($endsOn)م@endif؛
        وبذلك تكون المدة هي: <span class="value">{{ $duration }}</span>
    </div>

    <div class="conditions">
        <div class="heading">الشروط</div>
        @foreach ($conditions as $condition)
            <div class="line">- {{ $condition }}</div>
        @endforeach
        <div class="note">• تنويه: يجب إرفاق صورة عن الهوية الشخصية / بيان عائلي / صورة عن الشهادة العلمية</div>
    </div>

    <br>
    <table class="signatures" width="100%">
        <tr>
            <td width="31%">توقيع المتعاقد</td>
            <td width="3.5%" style="border: none"></td>
            <td width="31%">رئيس فرع القوى البشرية</td>
            <td width="3.5%" style="border: none"></td>
            <td width="31%">قائد السوية التنظيمية الأولى</td>
        </tr>
    </table>

    <div class="dated">في: <span class="value">{{ $hijri }}</span>هـ - الموافق: <span class="value">{{ $gregorian }}</span>مـ</div>
</div>
