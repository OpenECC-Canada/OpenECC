<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/pdf/OfficialPdfText.php';

/**
 * Maps one released resource onto the official ICS Canada 221 Demobilization Check-Out. Command Bridge fills in what
 * it knows (the incident, the resource, its demob number and any release details); the units that must sign the
 * resource out are ticked, and their sign-offs, transport and notifications are completed by hand.
 */
final class Ics221OfficialPdfFieldMapper
{
    /**
     * $intake: a resource_unit_intake row. $demob: its resource_demob_tracker row (may be empty).
     * $preparedBy: "Name, Position". Returns [fields, continuations].
     *
     * @return array{fields: array<string, mixed>, continuations: array<int, array{heading: string, text: string}>}
     */
    public static function build(array $incident, array $intake, array $demob, string $preparedBy, string $now): array
    {
        $cont = [];
        $kindType = trim(trim((string)($intake['resource_kind'] ?? '')) . ' / ' . trim((string)($intake['resource_type'] ?? '')), ' /');
        $qty = trim((string)($intake['quantity_received'] ?? ''));
        $released = array_filter([
            trim((string)($intake['source_request_number'] ?? '')),
            $kindType,
            trim(preg_replace('/\s+/', ' ', (string)($intake['resource_description'] ?? '')) ?? ''),
            $qty !== '' && $qty !== '0' ? 'Qty ' . $qty : '',
        ], static fn($v) => $v !== '');
        $number = trim((string)($incident['incident_number'] ?? ''));
        $demobId = (int)($demob['id'] ?? 0) ?: (int)($intake['id'] ?? 0);
        $equipment = in_array((string)($intake['resource_category'] ?? ''), ['vehicle', 'equipment'], true);
        $remarks = array_filter([
            trim((string)($demob['release_notes'] ?? '')),
            trim((string)($demob['demobilization_reason'] ?? '')),
            trim((string)($intake['supplier_contact'] ?? '')) !== '' ? 'Supplier contact: ' . trim((string)$intake['supplier_contact']) : '',
        ]);
        $f = [
            '1 INCIDENT NAMENUMBER' => ics_pdf_incident_name($incident) . ($number !== '' ? ' / ' . $number : ''),
            '2 DATETIME' => ics_pdf_fmt($now, 'M j, Y H:i'),
            '3 DEMOB NUMBER' => 'DM-' . $demobId,
            '4 UNITPERSONNEL RELEASED' => implode(' - ', $released),
            '6 ACTUAL RELEASE DATETIME' => !empty($demob['demobilized_at']) ? ics_pdf_fmt($demob['demobilized_at'], 'M j, Y H:i') : '',
            '8 DESTINATION' => (string)($demob['release_location'] ?? ''),
            'REMARKS' => implode("\n", $remarks),
            'Text60' => '1', 'Text61' => '1',
            '13 PREPARED BY include date and time' => $preparedBy . ' - ' . ics_pdf_fmt($now, 'M j, Y H:i'),
        ];
        $headings = ['4 UNITPERSONNEL RELEASED' => 'Unit/personnel released', 'REMARKS' => 'Remarks', '8 DESTINATION' => 'Destination'];
        $fields = OfficialPdfText::apply('ICS221', OfficialPdfText::clean($f), $headings, $cont);
        // Units that sign the resource out (block 11). Ground Support only for vehicles and equipment.
        foreach (['LOGISTICS SECTION Supply Unit CheckBox', 'LOGISTICS SECTION Communication Unit CheckBox', 'LOGISTICS SECTION Facilities Unit CheckBox',
                     'PLANNING SECTION Documentation Unit CheckBox', 'FINANCE SECTION Time Unit CheckBox'] as $box) {
            $fields[$box] = OfficialPdfText::check();
        }
        if ($equipment) { $fields['LOGISTICS SECTION Ground Support Unit CheckBox'] = OfficialPdfText::check(); }
        return ['fields' => $fields, 'continuations' => $cont];
    }
}
