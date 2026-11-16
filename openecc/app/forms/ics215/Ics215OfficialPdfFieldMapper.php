<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps the ICS 215 Operational Planning Worksheet onto the official ICS Canada 215 template: 12 resource columns and
 * 6 work assignments a page. Each assignment has Req. / Have / Need lines per resource column. More assignments
 * continue on another copy of the form; the totals (blocks 11-13) cover every assignment and are printed on the last
 * page. Text that does not fit its box goes to the continuation sheet.
 */
final class Ics215OfficialPdfFieldMapper
{
    public const ROWS_PER_PAGE = 6;
    public const COLUMNS = 12;

    /** Field for a resource column heading (block 6). */
    public static function headingField(int $col): string
    {
        return $col === 0 ? 'Text24.0.0' : ($col === 1 ? 'Text24.0.1' : 'Text24.' . ($col - 1));
    }

    /** Field for row $row (0-5), line $line (0 = Req., 1 = Have, 2 = Need), column $col (0-11). */
    public static function cellField(int $row, int $line, int $col): string
    {
        if ($row === 5) {   // the last row's fields are named differently on the template
            return $line === 2 ? 'Text26.16.' . $col : 'Text26.15.' . $col . '.' . $line;
        }
        return 'Text26.' . ($row * 3 + $line) . '.' . $col;
    }

    /**
     * $header: resource_columns (list of up to 12 labels), prepared_by_name, prepared_by_position, prepared_at.
     * $rows: branch, division_group, work_assignment, resources (col => ['req' => int|null, 'have' => int|null]),
     * overhead, special_equipment, reporting_location, arrival_time.
     *
     * @return array{pages: array<int, array<string, mixed>>, continuations: array<int, array{heading: string, text: string}>}
     */
    public static function build(array $header, array $incident, array $op, array $rows): array
    {
        $cont = [];
        $p = OfficialPdfText::opParts($op, 'M j, Y');
        $cols = array_slice(array_values((array)($header['resource_columns'] ?? [])), 0, self::COLUMNS);
        $common = [
            'Text32' => ics_pdf_incident_name($incident),
            'Text34.0.0' => $p['from_date'], 'Text34.1.0' => $p['from_time'],
            'Text34.0.1' => $p['to_date'], 'Text34.1.1' => $p['to_time'],
        ];
        // The column headings are rotated boxes (text runs up the page), so they skip the width check in apply().
        $headingFields = [];
        foreach ($cols as $c => $label) {
            if (trim((string)$label) !== '') { $headingFields[self::headingField($c)] = mb_substr(trim((string)$label), 0, 24); }
        }

        $rows = array_values($rows);
        $totals = [];
        foreach ($rows as $r) {
            foreach ((array)($r['resources'] ?? []) as $c => $v) {
                $c = (int)$c;
                if ($c < 0 || $c >= self::COLUMNS) { continue; }
                [$req, $have, $need] = self::numbers($v);
                if ($req === null && $have === null) { continue; }
                $totals[$c] = [($totals[$c][0] ?? 0) + (int)$req, ($totals[$c][1] ?? 0) + (int)$have, ($totals[$c][2] ?? 0) + (int)$need];
            }
        }

        $chunks = array_chunk($rows, self::ROWS_PER_PAGE) ?: [[]];
        $pages = [];
        foreach ($chunks as $pi => $chunk) {
            $f = $common;
            $headings = [];
            foreach ($chunk as $i => $r) {
                $label = 'Assignment ' . ($pi * self::ROWS_PER_PAGE + $i + 1);
                $text = [
                    '1 Incident NameRow' . ($i + 2) => [(string)($r['branch'] ?? ''), 'Branch'],
                    '1 Incident NameRow' . ($i + 2) . '_2' => [(string)($r['division_group'] ?? ''), 'Division / group'],
                    '5 Work Assignment  Special InstructionsRow' . ($i + 1) => [(string)($r['work_assignment'] ?? ''), 'Work assignment and special instructions'],
                    'Text29.' . $i . '.0' => [(string)($r['overhead'] ?? ''), 'Overhead positions'],
                    'Text29.' . $i . '.1' => [(string)($r['special_equipment'] ?? ''), 'Special equipment and supplies'],
                    'Text30.' . $i . '.0' => [(string)($r['reporting_location'] ?? ''), 'Reporting location'],
                    'Text30.' . $i . '.1' => [(string)($r['arrival_time'] ?? ''), 'Requested arrival time'],
                ];
                foreach ($text as $field => [$value, $what]) {
                    $f[$field] = $value;
                    $headings[$field] = $label . ' - ' . $what;
                }
                foreach ((array)($r['resources'] ?? []) as $c => $v) {
                    $c = (int)$c;
                    if ($c < 0 || $c >= self::COLUMNS) { continue; }
                    [$req, $have, $need] = self::numbers($v);
                    if ($req === null && $have === null) { continue; }
                    $f[self::cellField($i, 0, $c)] = (string)(int)$req;
                    $f[self::cellField($i, 1, $c)] = (string)(int)$have;
                    $f[self::cellField($i, 2, $c)] = (string)$need;
                }
            }
            if ($pi === count($chunks) - 1) {
                foreach ($totals as $c => [$req, $have, $need]) {
                    $f['Text27.0.' . $c] = (string)$req;
                    $f['Text27.1.' . $c] = (string)$have;
                    $f['Text27.2.' . $c] = (string)$need;
                }
                $preparedAt = (string)($header['prepared_at'] ?? '');
                $f['Text33.0'] = (string)($header['prepared_by_name'] ?? '');
                $f['Text33.1'] = (string)($header['prepared_by_position'] ?? '');
                $f['Text33.2'] = $preparedAt !== '' ? ics_pdf_fmt($preparedAt, 'M j, Y H:i') : '';
            }
            $pages[] = OfficialPdfText::apply('ICS215', OfficialPdfText::clean($f), $headings, $cont) + $headingFields;
        }
        return ['pages' => $pages, 'continuations' => $cont];
    }

    /** [required, have, need] for one cell; need is never below zero. Nulls when nothing was entered. */
    public static function numbers($v): array
    {
        $req = isset($v['req']) && $v['req'] !== '' && $v['req'] !== null ? max(0, (int)$v['req']) : null;
        $have = isset($v['have']) && $v['have'] !== '' && $v['have'] !== null ? max(0, (int)$v['have']) : null;
        if ($req === null && $have === null) { return [null, null, null]; }
        return [$req, $have, max(0, (int)$req - (int)$have)];
    }
}
