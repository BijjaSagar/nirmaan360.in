<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;

/** Renders a Blade view to PDF bytes for the report PDFs. */
final class PdfRenderer
{
    /**
     * Fonts are subset, so a one-page report is a few kilobytes rather than the whole font file each time,
     * which matters for email attachments and WhatsApp links. dompdf leaves large reference cycles
     * behind, so they are collected here or a run of PDFs in one process would grow without bound.
     */
    public static function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        Memory::ensure();

        $pdf = Pdf::loadView($view, $data)->setPaper('a4', $orientation)->setOption('enable_font_subsetting', true);
        $bytes = $pdf->output();

        unset($pdf);
        gc_collect_cycles();

        return $bytes;
    }
}
