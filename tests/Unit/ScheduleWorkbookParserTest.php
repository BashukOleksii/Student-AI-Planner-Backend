<?php

namespace Tests\Unit;

use App\Exceptions\ScheduleWorkbookParserException;
use App\Services\Imports\ScheduleWorkbookParser;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Deliberately uses plain PHPUnit: no Laravel boot, authentication, or database.
class ScheduleWorkbookParserTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx';

    public function test_real_workbook_produces_exact_weekly_template_without_inferred_fields(): void
    {
        $parser = new ScheduleWorkbookParser;
        $result = $parser->parse(self::FIXTURE);

        $this->assertSame('278fcd21f10da37f9eededdccf638e041236fb1c61e0c7a37a329aee59a4ddc9', hash_file('sha256', self::FIXTURE));
        $this->assertSame(['group', 'source', 'rules'], array_keys($result));
        $this->assertSame('ПІ-231', $result['group']);
        $this->assertSame(['worksheet' => 'Sheet1', 'range' => 'A1:F81'], $result['source']);
        $this->assertCount(16, $result['rules']);

        // Literal normalized fixture text, including Cyrillic а in room identifiers.
        $expected = [
            [1, '1-2', 'all', 'Програмування в Інтернет', 'Когут В.С.', '231а', 3, 2],
            [1, '3-4', 'all', 'Людино-машинна взаємодія', 'Кондратюк Є.С.', '231', 7, 6],
            [1, '5-6', 'all', 'Машинне навчання', 'Барабаш Д.С.', '231а', 11, 10],
            [1, '7-8', 'all', 'Філософія', 'Багас Л.М.', '129', 15, 14],
            [2, '1-2', 'all', 'Конструювання прогр забезпечення', 'Щуцький С.В.', '243а', 19, 18],
            [2, '3-4', 'all', 'Фізичне виховання', 'Бєльський А.В.', null, 23, 22],
            [2, '5-6', 'all', 'Машинне навчання', 'Барабаш Д.С.', '232', 27, 26],
            [2, '7-8', 'numerator', 'Філософія', 'Багас Л.М.', '122', 30, 30],
            [2, '7-8', 'denominator', 'Проект інтерфейсів користувача ПС', 'Атаман В.О.', '221', 32, 30],
            [3, '3-4', 'all', 'Архітектура та проект ПЗ', 'Ковальчук С.О.', '245', 39, 38],
            [3, '5-6', 'all', 'Конструювання прогр забезпечення', 'Щуцький С.В.', '243а', 43, 42],
            [4, '1-2', 'all', 'Людино-машинна взаємодія', 'Кондратюк Є.С.', '231', 51, 50],
            [4, '3-4', 'all', 'Проект інтерфейсів користувача ПС', 'Атаман В.О.', '231', 55, 54],
            [4, '5-6', 'all', 'Програмування в Інтернет', 'Когут В.С.', '231а', 59, 58],
            [5, '1-2', 'all', 'Конструювання прогр забезпечення', 'Щуцький С.В.', '243а', 67, 66],
            [5, '3-4', 'all', 'Архітектура та проект ПЗ', 'Ковальчук С.О.', '245', 71, 70],
        ];
        $times = [
            '1-2' => ['08:30:00', '09:50:00'],
            '3-4' => ['10:00:00', '11:20:00'],
            '5-6' => ['11:50:00', '13:10:00'],
            '7-8' => ['13:20:00', '14:40:00'],
        ];
        foreach ($expected as $index => [$weekday, $pair, $parity, $subject, $teacher, $room, $subjectRow, $pairRow]) {
            $this->assertSame([
                'weekday' => $weekday,
                'pair_label' => $pair,
                'starts_at_local' => $times[$pair][0],
                'ends_at_local' => $times[$pair][1],
                'parity' => $parity,
                'subject_name' => $subject,
                'teacher_name' => $teacher,
                'room' => $room,
                'source' => [
                    'worksheet' => 'Sheet1',
                    'rows' => [$subjectRow, $subjectRow + 1],
                    'range' => 'D'.$subjectRow.':F'.($subjectRow + 1),
                    'pair_range' => 'B'.$pairRow.':F'.($pairRow + 3),
                ],
            ], $result['rules'][$index]);
        }

        $split = array_values(array_filter($result['rules'], fn (array $rule) => $rule['weekday'] === 2 && $rule['pair_label'] === '7-8'));
        $this->assertSame(['numerator', 'denominator'], array_column($split, 'parity'));
        // Exact equality also excludes the empty slots, right-hand examples, type, IDs and dates.
        $this->assertSame($result, $parser->parse(self::FIXTURE));
    }

    public function test_region_can_be_shifted_and_times_and_whitespace_are_read_from_cells(): void
    {
        $workbook = $this->emptyDialect(left: 8, top: 5);
        $sheet = $workbook->getActiveSheet();
        $sheet->setCellValue('K7', " \u{00A0}Предмет   з\nпробілами \u{00A0}");
        $sheet->mergeCells('K7:M7');
        $sheet->setCellValue('K8', "  Викладач\tІ.П.  ");
        $sheet->mergeCells('K8:L8');
        $sheet->setCellValue('M8', " \u{00A0} ");
        $sheet->setCellValue('J7', ' 9:05 ');
        $sheet->setCellValue('J8', '10:25:30');
        // Formula outside the identified schedule is irrelevant and must not be evaluated.
        $sheet->setCellValue('U1', '=1/0');

        $result = $this->parseGenerated($workbook);

        $this->assertSame(['worksheet' => 'Sheet1', 'range' => 'H5:M85'], $result['source']);
        $this->assertCount(1, $result['rules']);
        $rule = $result['rules'][0];
        $this->assertSame('Предмет з пробілами', $rule['subject_name']);
        $this->assertSame('Викладач І.П.', $rule['teacher_name']);
        $this->assertNull($rule['room']);
        $this->assertSame('09:05:00', $rule['starts_at_local']);
        $this->assertSame('10:25:30', $rule['ends_at_local']);
        $this->assertSame('K7:M8', $rule['source']['range']);
    }

    public function test_tiny_unsupported_layout_throws_controlled_error(): void
    {
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setCellValue('C1', 'Група')->setCellValue('D1', 'ПІ-231');

        $this->expectException(ScheduleWorkbookParserException::class);
        $this->expectExceptionMessage('No supported schedule region');
        $this->parseGenerated($workbook);
    }

    #[DataProvider('malformedSchedules')]
    public function test_malformed_schedule_is_rejected(string $case, string $message): void
    {
        $workbook = $this->emptyDialect();
        $sheet = $workbook->getActiveSheet();
        match ($case) {
            'formula' => $sheet->setCellValue('C3', '=TIME(8,30,0)'),
            'subject formula' => $sheet->setCellValue('D3', '=CONCAT("Пред", "мет")'),
            'teacher formula' => $sheet->setCellValue('D4', '=CONCAT("Викладач", " І.П.")'),
            'room formula' => $sheet->setCellValue('F4', '=200+31'),
            'missing teacher' => $sheet->setCellValue('D3', 'Предмет'),
            'invalid time' => $sheet->setCellValue('C3', 2.5),
            'reversed time' => $sheet->setCellValue('C3', '23:00'),
            'orphan room' => $sheet->setCellValue('F4', '231'),
            'wrong pair merge' => $sheet->unmergeCells('B2:B5'),
            'vertical subject merge' => $sheet->mergeCells('D3:D4'),
            'missing weekday' => $sheet->setCellValue('A66', 'Субота'),
        };

        $this->expectException(ScheduleWorkbookParserException::class);
        $this->expectExceptionMessage($message);
        $this->parseGenerated($workbook);
    }

    public static function malformedSchedules(): array
    {
        return [
            ['formula', 'Formulas in schedule cells are unsupported at Sheet1!C3'],
            ['subject formula', 'Formulas in schedule cells are unsupported at Sheet1!D3'],
            ['teacher formula', 'Formulas in schedule cells are unsupported at Sheet1!D4'],
            ['room formula', 'Formulas in schedule cells are unsupported at Sheet1!F4'],
            ['missing teacher', 'Unsupported subject/teacher row structure'],
            ['invalid time', 'Expected an Excel time fraction'],
            ['reversed time', 'Pair start must precede end'],
            ['orphan room', 'Unsupported teacher/room column structure'],
            ['wrong pair merge', 'No supported schedule region'],
            ['vertical subject merge', 'Unsupported merge geometry'],
            ['missing weekday', 'No supported schedule region'],
        ];
    }

    public function test_two_complete_regions_are_ambiguous(): void
    {
        $workbook = $this->emptyDialect();
        $duplicate = clone $workbook->getActiveSheet();
        $duplicate->setTitle('Another schedule');
        $workbook->addSheet($duplicate);

        $this->expectException(ScheduleWorkbookParserException::class);
        $this->expectExceptionMessage('Ambiguous workbook');
        $this->parseGenerated($workbook);
    }

    public function test_unreadable_file_throws_controlled_error(): void
    {
        $this->expectException(ScheduleWorkbookParserException::class);
        $this->expectExceptionMessage('Unable to read the XLSX');
        (new ScheduleWorkbookParser)->parse(__DIR__.'/missing-schedule.xlsx');
    }

    private function emptyDialect(int $left = 1, int $top = 1): Spreadsheet
    {
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Sheet1');
        $cell = fn (int $column, int $row) => Coordinate::stringFromColumnIndex($column).$row;
        $sheet->setCellValue($cell($left + 2, $top), 'Група');
        $sheet->setCellValue($cell($left + 3, $top), 'ПІ-231');
        $sheet->mergeCells($cell($left + 3, $top).':'.$cell($left + 5, $top));
        foreach (['Понеділок', 'Вівторок', 'Середа', 'Четвер', "П'ятниця"] as $day => $name) {
            $row = $top + 1 + $day * 16;
            $sheet->setCellValue($cell($left, $row), $name);
            $sheet->mergeCells($cell($left, $row).':'.$cell($left, $row + 15));
            foreach (['1-2', '3-4', '5-6', '7-8'] as $pair => $label) {
                $pairRow = $row + $pair * 4;
                $sheet->setCellValue($cell($left + 1, $pairRow), $label);
                $sheet->mergeCells($cell($left + 1, $pairRow).':'.$cell($left + 1, $pairRow + 3));
                $sheet->setCellValue($cell($left + 2, $pairRow + 1), (8 * 60 + 30 + $pair * 90) / 1440);
                $sheet->setCellValue($cell($left + 2, $pairRow + 2), (9 * 60 + 50 + $pair * 90) / 1440);
            }
        }

        return $workbook;
    }

    private function parseGenerated(Spreadsheet $workbook): array
    {
        $path = tempnam(sys_get_temp_dir(), 'schedule-parser-');
        try {
            (new Xlsx($workbook))->setPreCalculateFormulas(false)->save($path);

            return (new ScheduleWorkbookParser)->parse($path);
        } finally {
            $workbook->disconnectWorksheets();
            unlink($path);
        }
    }
}
