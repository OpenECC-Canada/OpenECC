<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/fpdf/fpdf.php';

/**
 * The official ICS Canada 233 has no fillable fields, so the text is drawn on an overlay and stamped onto it
 * (OfficialPdfTemplateExporter::stampTemplate()).
 *
 * The template is a portrait page shown landscape (/Rotate 90). pdftk lays a stamp over the page as it is shown, so the
 * overlay is a plain landscape page and the measurements below are in that view (x across, y down, points), taken from
 * the template. Re-measure if the template is replaced. Uses the bundled FPDF (app/vendor/fpdf).
 */
final class Ics233OfficialPdfOverlay
{
    public const ROWS_PER_PAGE = 34;
    /** Column => [x left, x right] in the landscape view. */
    private const COLS = [
        'item' => [77.8, 262.2], 'poc' => [262.2, 325.5], 'briefed' => [325.5, 376.0], 'start' => [376.0, 433.8],
        'status' => [433.8, 629.2], 'target' => [629.2, 682.8], 'actual' => [682.8, 732.5],
    ];
    /** Row lines (y) in the landscape view: 35 lines bound the 34 rows. */
    private const ROW_LINES = [148.2, 160.8, 172.8, 185.2, 197.8, 209.8, 222.2, 234.8, 247.2, 259.2, 271.8, 284.2, 296.5, 308.8, 321.2,
        333.5, 345.8, 358.2, 370.8, 383.0, 395.2, 407.8, 420.2, 432.2, 444.8, 457.2, 469.8, 482.8, 495.8, 508.8, 521.2, 533.8, 545.8,
        558.2, 570.5];

    /**
     * Writes the overlay PDF and returns [path, page count]. $rows: each with item_no, item, poc, briefed (bool),
     * start_date, status_text, target_date, actual_date.
     *
     * @return array{0: string, 1: int}
     */
    public static function write(string $incidentName, array $rows, string $tempDir): array
    {
        $pdf = new Ics233OverlayPdf('L', 'pt', 'Letter');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $chunks = array_chunk(array_values($rows), self::ROWS_PER_PAGE) ?: [[]];
        $total = count($chunks);
        foreach ($chunks as $n => $chunk) {
            $pdf->AddPage();
            self::draw($pdf, 132, 260, 37, 62, $incidentName, 10);       // 1. Incident Name
            self::draw($pdf, 396, 412, 575, 590, (string)($n + 1), 8);    // Page __
            self::draw($pdf, 416, 432, 575, 590, (string)$total, 8);      // of __
            foreach ($chunk as $i => $r) {
                $y0 = self::ROW_LINES[$i];
                $y1 = self::ROW_LINES[$i + 1];
                // The rows are numbered 1-34 on the form; when the tracker's own number differs (earlier actions
                // completed, or a second page), it goes in front of the item.
                $no = (int)($r['item_no'] ?? 0);
                $values = [
                    'item' => ($no > 0 && $no !== $i + 1 ? 'No. ' . $no . ': ' : '') . (string)($r['item'] ?? ''),
                    'poc' => (string)($r['poc'] ?? ''),
                    'briefed' => !empty($r['briefed']) ? 'X' : '',
                    'start' => ics_pdf_fmt($r['start_date'] ?? '', 'M j'),
                    'status' => (string)($r['status_text'] ?? ''),
                    'target' => ics_pdf_fmt($r['target_date'] ?? '', 'M j'),
                    'actual' => ics_pdf_fmt($r['actual_date'] ?? '', 'M j'),
                ];
                foreach ($values as $col => $value) {
                    [$x0, $x1] = self::COLS[$col];
                    self::draw($pdf, $x0, $x1, $y0, $y1, $value, 7);
                }
            }
        }
        $path = (string)tempnam($tempDir, 'cb233_');
        if ($path === '') {
            throw new RuntimeException('Unable to create a temporary PDF file.');
        }
        $pdf->Output('F', $path);
        return [$path, $total];
    }

    private static function draw(Ics233OverlayPdf $pdf, float $x0, float $x1, float $y0, float $y1, string $text, float $size): void
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') { return; }
        $pdf->fitBox($x0, $x1, $y0, $y1, $text, $size);
    }
}

/** FPDF with word-wrapped, shrink-to-fit text boxes. */
final class Ics233OverlayPdf extends FPDF
{
    // The bundled FPDF build reads these in _putcatalog() without declaring them.
    public $ZoomMode = 'default';
    public $LayoutMode = 'default';

    public function fitBox(float $x0, float $x1, float $y0, float $y1, string $text, float $size): void
    {
        $text = (string)@iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
        $width = $x1 - $x0 - 4;
        $height = $y1 - $y0 - 1;
        $lines = [$text];
        for ($fs = $size; $fs >= 4.5; $fs -= 0.5) {
            $this->SetFont('Helvetica', '', $fs);
            $lines = $this->wrapLines($text, $width);
            if (count($lines) * $fs * 1.05 <= $height) { break; }
        }
        $lead = $fs * 1.05;
        $lines = array_slice($lines, 0, max(1, (int)floor($height / $lead)));
        $y = $y0 + max(0.0, ($height - count($lines) * $lead) / 2) + 0.5;
        foreach ($lines as $line) {
            $this->Text($x0 + 2, $y + $fs * 0.82, $line);
            $y += $lead;
        }
    }

    /** @return array<int, string> */
    private function wrapLines(string $text, float $width): array
    {
        $out = [];
        foreach (explode("\n", $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->GetStringWidth($try) > $width) { $out[] = $line; $line = $word; } else { $line = $try; }
            }
            $out[] = $line;
        }
        return $out;
    }
}
