<?php

namespace App\Modules\Reports\Exports;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;

/** Renders a Blade view to a right-to-left Arabic PDF (mPDF), with the page number in the footer. */
final class Pdf
{
    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data, ReportHeader $header, bool $landscape = false): string
    {
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $landscape ? 'A4-L' : 'A4',
            'default_font' => 'xbriyaz',
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_top' => 14,
            'margin_bottom' => 16,
            'margin_footer' => 6,
        ]);

        $pdf->SetTitle($header->title);
        $pdf->SetAuthor($header->issuer->full_name);
        $pdf->SetHTMLFooter('<table width="100%" style="font-size:8pt;color:#555"><tr>'
            . '<td>' . e($header->organization()) . ' — ' . e($header->title) . '</td>'
            . '<td style="text-align:left">' . e(__('reports.page', [], 'ar')) . ' {PAGENO} / {nbpg}</td>'
            . '</tr></table>');
        $pdf->WriteHTML(view($view, $data + ['header' => $header])->render());

        return $pdf->Output('', 'S');
    }
}
