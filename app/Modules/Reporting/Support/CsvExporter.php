<?php

namespace App\Modules\Reporting\Support;

/**
 * A dashboard as a CSV file that opens correctly in Excel: UTF-8 with a byte-order mark, a section per table, raw numbers (not
 * "LKR 1,234.50") so they can be summed. Text that a spreadsheet would run as a formula is neutralised with a leading apostrophe.
 */
final class CsvExporter
{
    /**
     * @param  array{kpis: list<array<string, mixed>>, charts: list<array<string, mixed>>, tables: list<array<string, mixed>>}  $data
     */
    public static function render(string $title, Period $period, ?Period $compare, array $data): string
    {
        $rows = [
            [$title],
            [__('reporting::ui.period'), $period->from, $period->to],
        ];
        if ($compare !== null) {
            $rows[] = [__('reporting::ui.compare_with'), $compare->from, $compare->to];
        }
        $rows[] = [];
        $rows[] = [__('reporting::ui.kpis'), __('reporting::ui.col_value'), __('reporting::ui.col_unit'), __('reporting::ui.col_previous'), __('reporting::ui.col_change'), __('reporting::ui.col_change_pct')];
        foreach ($data['kpis'] as $k) {
            $rows[] = [$k['label'], $k['value'], $k['unit'], $k['previous'], $k['change'], $k['change_pct']];
        }
        foreach ($data['tables'] as $t) {
            $rows[] = [];
            $rows[] = [$t['title']];
            $rows[] = array_column($t['columns'], 'label');
            foreach ($t['rows'] as $r) {
                $line = [];
                foreach ($t['columns'] as $col) {
                    $v = $r['cells'][$col['key']] ?? null;
                    $line[] = is_bool($v) ? ($v ? __('reporting::ui.yes') : __('reporting::ui.no')) : $v;
                }
                $rows[] = $line;
            }
        }

        $h = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($h, array_map([self::class, 'cell'], $row), ',', '"', '\\', "\r\n");
        }
        rewind($h);

        return "\xEF\xBB\xBF".stream_get_contents($h);
    }

    public static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = (string) $v;
        if ($s !== '' && preg_match('/^-?\d+(\.\d+)?$/', $s) !== 1 && strpbrk($s[0], "=+-@\t\r") !== false) {
            return "'".$s;
        }

        return $s;
    }
}
