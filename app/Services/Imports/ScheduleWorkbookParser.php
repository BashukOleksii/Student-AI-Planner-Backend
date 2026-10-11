<?php

namespace App\Services\Imports;

use App\Exceptions\ScheduleWorkbookParserException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ScheduleWorkbookParser
{
    private const WEEKDAYS = ['Понеділок', 'Вівторок', 'Середа', 'Четвер', "П'ятниця"];

    private const PAIRS = ['1-2', '3-4', '5-6', '7-8'];

    /**
     * Parse the supported five-day, four-pair dialect without application state.
     * Times are local wall-clock values; source rows are inclusive and 1-based.
     *
     * @return array{
     *   group: string,
     *   source: array{worksheet: string, range: string},
     *   rules: list<array{
     *     weekday: int, pair_label: string, starts_at_local: string, ends_at_local: string,
     *     parity: 'all'|'numerator'|'denominator', subject_name: string,
     *     teacher_name: string, room: ?string,
     *     source: array{worksheet: string, rows: array{int, int}, range: string, pair_range: string}
     *   }>
     * }
     */
    public function parse(string $path): array
    {
        try {
            // Keep merges; never calculate formulas or use formatted cell values.
            $workbook = (new Xlsx)->load($path);
        } catch (\Exception $exception) {
            throw new ScheduleWorkbookParserException('Unable to read the XLSX schedule workbook.', 0, $exception);
        }

        try {
            $regions = [];
            foreach ($workbook->getWorksheetIterator() as $sheet) {
                $merges = array_map(Coordinate::rangeBoundaries(...), array_values($sheet->getMergeCells()));
                // Search only for structural headers, never for lesson-looking text.
                foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                    [$column, $row] = Coordinate::indexesFromString($coordinate);
                    if ($column >= 3 && $this->text($this->rawValue($sheet, $column, $row)) === 'Група') {
                        $left = $column - 2;
                        if ($this->isRegion($sheet, $merges, $left, $row)) {
                            $regions[] = [$sheet, $merges, $left, $row];
                        }
                    }
                }
            }

            if (count($regions) !== 1) {
                throw new ScheduleWorkbookParserException($regions === []
                    ? 'No supported schedule region found: expected a group header and five contiguous weekday blocks with four pairs each.'
                    : 'Ambiguous workbook: multiple supported schedule regions found.');
            }

            [$sheet, $merges, $left, $top] = $regions[0];
            $this->validateRegion($sheet, $merges, $left, $top);
            $rules = [];
            foreach (self::WEEKDAYS as $day => $name) {
                foreach (self::PAIRS as $pair => $label) {
                    $row = $top + 1 + $day * 16 + $pair * 4;
                    array_push($rules, ...$this->parseSlot($sheet, $merges, $left, $row, $day + 1, $label));
                }
            }

            return [
                'group' => $this->text($this->value($sheet, $merges, $left + 3, $top)),
                'source' => ['worksheet' => $sheet->getTitle(), 'range' => $this->range($left, $top, $left + 5, $top + 80)],
                'rules' => $rules,
            ];
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    private function isRegion(Worksheet $sheet, array $merges, int $left, int $top): bool
    {
        if ($this->mergeAt($merges, $left + 3, $top) !== [[$left + 3, $top], [$left + 5, $top]]
            || $this->text($this->rawValue($sheet, $left + 3, $top)) === null) {
            return false;
        }

        foreach (self::WEEKDAYS as $day => $name) {
            $row = $top + 1 + $day * 16;
            if ($this->text($this->rawValue($sheet, $left, $row)) !== $name
                || $this->mergeAt($merges, $left, $row) !== [[$left, $row], [$left, $row + 15]]) {
                return false;
            }
            foreach (self::PAIRS as $pair => $label) {
                $pairRow = $row + $pair * 4;
                if ($this->text($this->rawValue($sheet, $left + 1, $pairRow)) !== $label
                    || $this->mergeAt($merges, $left + 1, $pairRow) !== [[$left + 1, $pairRow], [$left + 1, $pairRow + 3]]
                    || $this->rawValue($sheet, $left + 2, $pairRow + 1) === null
                    || $this->rawValue($sheet, $left + 2, $pairRow + 2) === null) {
                    return false;
                }
            }
        }

        return true;
    }

    private function validateRegion(Worksheet $sheet, array $merges, int $left, int $top): void
    {
        foreach ($merges as [$start, $end]) {
            if ($end[0] < $left || $start[0] > $left + 5 || $end[1] < $top || $start[1] > $top + 80) {
                continue;
            }
            // Vertical merging is reserved for weekday/pair labels; content merges are horizontal.
            $structural = ($start[0] === $left && $end[0] === $left)
                || ($start[0] === $left + 1 && $end[0] === $left + 1);
            $content = $start[0] === $left + 3 && $end[0] <= $left + 5 && $start[1] === $end[1];
            if ($start[0] < $left || $end[0] > $left + 5 || $start[1] < $top || $end[1] > $top + 80
                || (! $structural && ! $content)) {
                $this->fail($sheet, $start[0], $start[1], 'Unsupported merge geometry in schedule region');
            }
        }

        for ($row = $top; $row <= $top + 80; $row++) {
            for ($column = $left; $column <= $left + 5; $column++) {
                $coordinate = Coordinate::stringFromColumnIndex($column).$row;
                if (! $sheet->cellExists($coordinate)) {
                    continue;
                }
                if ($sheet->getCell($coordinate)->getDataType() === DataType::TYPE_FORMULA) {
                    $this->fail($sheet, $column, $row, 'Formulas in schedule cells are unsupported');
                }
                $merge = $this->mergeAt($merges, $column, $row);
                if ($merge !== null && $merge[0] !== [$column, $row]
                    && $this->text($this->rawValue($sheet, $column, $row)) !== null) {
                    $this->fail($sheet, $column, $row, 'Hidden content in a merged schedule cell');
                }
                if ($row > $top && $column <= $left + 2) {
                    $offset = $row - $top - 1;
                    $allowed = ($column === $left && $offset % 16 === 0)
                        || ($column === $left + 1 && $offset % 4 === 0)
                        || ($column === $left + 2 && in_array($offset % 4, [1, 2], true));
                    if (! $allowed && $this->text($this->rawValue($sheet, $column, $row)) !== null) {
                        $this->fail($sheet, $column, $row, 'Unexpected content in schedule labels/time columns');
                    }
                }
            }
        }
    }

    private function parseSlot(Worksheet $sheet, array $merges, int $left, int $row, int $weekday, string $label): array
    {
        $start = $this->time($sheet, $left + 2, $row + 1);
        $end = $this->time($sheet, $left + 2, $row + 2);
        if ($start >= $end) {
            $this->fail($sheet, $left + 2, $row + 1, 'Pair start must precede end on the same local day');
        }

        $occupied = [];
        for ($offset = 0; $offset < 4; $offset++) {
            if ($this->text($this->value($sheet, $merges, $left + 3, $row + $offset)) !== null) {
                $occupied[] = $offset;
            }
        }
        $lessons = match ($occupied) {
            [] => [],
            [1, 2] => [[$row + 1, 'all']],
            // A four-row pair containing two subject/teacher rows is upper/lower parity.
            [0, 1, 2, 3] => [[$row, 'numerator'], [$row + 2, 'denominator']],
            default => $this->fail($sheet, $left + 3, $row, 'Unsupported subject/teacher row structure'),
        };

        $teacherRows = array_map(fn (array $lesson) => $lesson[0] + 1, $lessons);
        for ($contentRow = $row; $contentRow <= $row + 3; $contentRow++) {
            if ($this->text($this->rawValue($sheet, $left + 4, $contentRow)) !== null
                || (! in_array($contentRow, $teacherRows, true) && $this->text($this->rawValue($sheet, $left + 5, $contentRow)) !== null)
                || (in_array($contentRow, $teacherRows, true) && ($this->mergeAt($merges, $left + 3, $contentRow)[1][0] ?? 0) === $left + 5)) {
                $this->fail($sheet, $left + 3, $contentRow, 'Unsupported teacher/room column structure');
            }
        }

        $rules = [];
        foreach ($lessons as [$subjectRow, $parity]) {
            $rules[] = [
                'weekday' => $weekday,
                'pair_label' => $label,
                'starts_at_local' => $start,
                'ends_at_local' => $end,
                'parity' => $parity,
                'subject_name' => $this->text($this->value($sheet, $merges, $left + 3, $subjectRow)),
                'teacher_name' => $this->text($this->value($sheet, $merges, $left + 3, $subjectRow + 1)),
                'room' => $this->text($this->value($sheet, $merges, $left + 5, $subjectRow + 1)),
                'source' => [
                    'worksheet' => $sheet->getTitle(),
                    'rows' => [$subjectRow, $subjectRow + 1],
                    'range' => $this->range($left + 3, $subjectRow, $left + 5, $subjectRow + 1),
                    'pair_range' => $this->range($left + 1, $row, $left + 5, $row + 3),
                ],
            ];
        }

        return $rules;
    }

    private function time(Worksheet $sheet, int $column, int $row): string
    {
        $value = $this->rawValue($sheet, $column, $row);
        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value < 1) {
            // Excel stores time as a day fraction. Round away floating-point artifacts.
            $seconds = (int) round($value * 86400);
            if ($seconds < 86400) {
                return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
            }
        } elseif (is_string($value) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $this->text($value) ?? '', $parts)) {
            return sprintf('%02d:%02d:%02d', (int) $parts[1], (int) $parts[2], (int) ($parts[3] ?? 0));
        }

        $this->fail($sheet, $column, $row, 'Expected an Excel time fraction or local HH:MM[:SS] time');
    }

    private function value(Worksheet $sheet, array $merges, int $column, int $row): mixed
    {
        [$column, $row] = $this->mergeAt($merges, $column, $row)[0] ?? [$column, $row];

        return $this->rawValue($sheet, $column, $row);
    }

    private function rawValue(Worksheet $sheet, int $column, int $row): mixed
    {
        $coordinate = Coordinate::stringFromColumnIndex($column).$row;

        return $sheet->cellExists($coordinate) ? $sheet->getCell($coordinate)->getValue() : null;
    }

    private function mergeAt(array $merges, int $column, int $row): ?array
    {
        foreach ($merges as [$start, $end]) {
            if ($column >= $start[0] && $column <= $end[0] && $row >= $start[1] && $row <= $end[1]) {
                return [$start, $end];
            }
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }
        $value = trim(preg_replace('/[\s\p{Z}]+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private function range(int $left, int $top, int $right, int $bottom): string
    {
        return Coordinate::stringFromColumnIndex($left).$top.':'.Coordinate::stringFromColumnIndex($right).$bottom;
    }

    private function fail(Worksheet $sheet, int $column, int $row, string $message): never
    {
        throw new ScheduleWorkbookParserException($message.' at '.$sheet->getTitle().'!'.Coordinate::stringFromColumnIndex($column).$row.'.');
    }
}
