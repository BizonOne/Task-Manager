<?php

namespace App\Support\Reports;

use App\Support\Brand;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/**
 * The report as a PDF, for sending on to somebody who will not log in.
 */
class PdfExport
{
    public function __construct(private TaskReport $report) {}

    public function download(): Response
    {
        $options = new Options;
        // The renderer defaults to a font with no Cyrillic, which turns every
        // Russian title into boxes. DejaVu Sans ships with it and covers both.
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', [public_path(), storage_path('app')]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->loadHtml(view('reports.pdf', [
            'report' => $this->report,
            'summary' => $this->report->summary(),
            'brandName' => Brand::name(),
            'accent' => Brand::primaryColor(),
        ])->render(), 'UTF-8');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->report->filename().'.pdf"',
        ]);
    }
}
