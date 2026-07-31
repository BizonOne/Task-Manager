<?php

namespace App\Support\Reports;

use App\Models\Task;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The report as a real .xlsx: a summary sheet and a row per task.
 *
 * Two sheets rather than one, because the two audiences differ — a manager
 * wants the totals, and whoever has to check them wants the tasks those
 * totals came from.
 */
class SpreadsheetExport
{
    private const HEADER_FILL = 'FF7C3AED';

    public function __construct(private TaskReport $report) {}

    public function download(): StreamedResponse
    {
        $book = new Spreadsheet;
        $book->getProperties()
            ->setTitle('Task report')
            ->setDescription($this->report->describe());

        $this->summarySheet($book);
        $this->taskSheet($book);
        $book->setActiveSheetIndex(0);

        $filename = $this->report->filename().'.xlsx';

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function summarySheet(Spreadsheet $book): void
    {
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Summary');

        $summary = $this->report->summary();
        $row = 1;

        $sheet->setCellValue('A'.$row, 'Task report');
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(16);
        $row++;

        $sheet->setCellValue('A'.$row, $this->report->describe());
        $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->getColor()->setARGB('FF6B7280');
        $row++;

        $sheet->setCellValue('A'.$row, 'Generated '.CarbonImmutable::now()->format('d M Y, H:i'));
        $sheet->getStyle('A'.$row)->getFont()->getColor()->setARGB('FF9CA3AF');
        $row += 2;

        foreach ([
            'Tasks' => $summary['total'],
            'Completed' => $summary['completed'],
            'Open' => $summary['open'],
            'Overdue' => $summary['overdue'],
            'Completion rate' => $summary['completion_rate'] / 100,
            'Estimated hours' => $summary['estimated_hours'],
        ] as $label => $value) {
            $sheet->setCellValue('A'.$row, $label);
            $sheet->setCellValue('B'.$row, $value);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            if ($label === 'Completion rate') {
                $sheet->getStyle('B'.$row)->getNumberFormat()->setFormatCode('0%');
            }
            $row++;
        }

        $row++;
        foreach ([
            'By status' => $this->report->byStatus()->map(fn ($r) => [$r['label'], $r['count'], null]),
            'By assignee' => $this->report->byAssignee()->map(fn ($r) => [$r['label'], $r['count'], $r['rate'] / 100]),
            'By project' => $this->report->byProject()->map(fn ($r) => [$r['label'], $r['count'], $r['rate'] / 100]),
            'By priority' => $this->report->byPriority()->map(fn ($r) => [$r['label'], $r['count'], $r['rate'] / 100]),
        ] as $heading => $rows) {
            $sheet->setCellValue('A'.$row, $heading);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
            $row++;

            $sheet->fromArray(['', 'Tasks', 'Completed'], null, 'A'.$row);
            $sheet->getStyle('A'.$row.':C'.$row)->getFont()->setBold(true);
            $row++;

            foreach ($rows as $values) {
                $sheet->fromArray($values, null, 'A'.$row);
                if ($values[2] !== null) {
                    $sheet->getStyle('C'.$row)->getNumberFormat()->setFormatCode('0%');
                }
                $row++;
            }
            $row++;
        }

        foreach (['A' => 34, 'B' => 14, 'C' => 14] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function taskSheet(Spreadsheet $book): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle('Tasks');

        $headers = ['Key', 'Title', 'Project', 'Assignee', 'Status', 'Priority', 'Due', 'Created', 'Est. hours'];
        $sheet->fromArray($headers, null, 'A1');

        $sheet->getStyle('A1:I1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:I1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->getStyle('A1:I1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($this->report->tasks() as $task) {
            /** @var Task $task */
            $sheet->fromArray([
                'TASK-'.str_pad((string) $task->id, 4, '0', STR_PAD_LEFT),
                $task->title,
                $task->project?->name,
                $task->user?->name,
                $task->status_label,
                ucfirst((string) $task->priority),
                $task->due_date ? CarbonImmutable::parse($task->due_date)->format('Y-m-d') : null,
                $task->created_at?->format('Y-m-d'),
                $task->estimated_hours === null ? null : (float) $task->estimated_hours,
            ], null, 'A'.$row);

            // Overdue and unfinished is the row a person is looking for.
            if ($task->due_date && ! $task->isCompleted()
                && CarbonImmutable::parse($task->due_date)->lt(CarbonImmutable::today())) {
                $sheet->getStyle('G'.$row)->getFont()->getColor()->setARGB('FFDC2626');
                $sheet->getStyle('G'.$row)->getFont()->setBold(true);
            }

            $row++;
        }

        $last = max($row - 1, 1);
        $sheet->getStyle('A1:I'.$last)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFE5E7EB');
        $sheet->setAutoFilter('A1:I'.$last);

        foreach (['A' => 12, 'B' => 52, 'C' => 22, 'D' => 20, 'E' => 16, 'F' => 12, 'G' => 12, 'H' => 12, 'I' => 11] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }
}
