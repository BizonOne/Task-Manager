{{--
    The report as a printable page.

    Everything is inline and table-based: the PDF renderer supports a narrow
    slice of CSS, and DejaVu Sans is the font that carries Cyrillic.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 22px 26px; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9px; color: #374151; margin: 0;
        }
        h1 { font-size: 16px; color: #111827; margin: 0 0 3px; }
        .sub { font-size: 8.5px; color: #6b7280; margin: 0 0 2px; }
        .muted { color: #9ca3af; }
        .rule { height: 3px; background: {{ $accent }}; margin: 8px 0 12px; }

        table { width: 100%; border-collapse: collapse; }
        .tiles td {
            width: 16.6%; padding: 8px; border: 1px solid #e5e7eb; text-align: center;
        }
        .tile-val { font-size: 15px; font-weight: bold; color: #111827; }
        .tile-lbl { font-size: 7.5px; color: #9ca3af; text-transform: uppercase; }
        .danger { color: #dc2626; }
        .ok { color: #16a34a; }

        h2 { font-size: 11px; color: #111827; margin: 14px 0 5px; }
        .grid td { vertical-align: top; width: 50%; padding-right: 10px; }

        .data th {
            background: {{ $accent }}; color: #fff; font-size: 8px; text-align: left;
            padding: 5px 6px; text-transform: uppercase;
        }
        .data td { padding: 4px 6px; border-bottom: 1px solid #eef0f3; font-size: 8.5px; }
        .data tr:nth-child(even) td { background: #fafbfc; }
        .breakdown td { padding: 3px 6px; border-bottom: 1px solid #eef0f3; font-size: 8.5px; }
        .breakdown td.n { text-align: right; width: 46px; }
        .key { color: #9ca3af; font-size: 8px; }
        .foot { margin-top: 14px; font-size: 7.5px; color: #b0b4be; text-align: center; }
    </style>
</head>
<body>
    <h1>Task report</h1>
    <p class="sub">{{ $report->describe() }}</p>
    <p class="sub muted">{{ $brandName }} &middot; generated {{ \App\Support\Dates::dateTime(now()) }}</p>
    <div class="rule"></div>

    <table class="tiles">
        <tr>
            <td><div class="tile-val">{{ $summary['total'] }}</div><div class="tile-lbl">Tasks</div></td>
            <td><div class="tile-val ok">{{ $summary['completed'] }}</div><div class="tile-lbl">Completed</div></td>
            <td><div class="tile-val">{{ $summary['open'] }}</div><div class="tile-lbl">Open</div></td>
            <td><div class="tile-val {{ $summary['overdue'] > 0 ? 'danger' : '' }}">{{ $summary['overdue'] }}</div><div class="tile-lbl">Overdue</div></td>
            <td><div class="tile-val">{{ $summary['completion_rate'] }}%</div><div class="tile-lbl">Completion</div></td>
            <td><div class="tile-val">{{ $summary['estimated_hours'] ?: '—' }}</div><div class="tile-lbl">Est. hours</div></td>
        </tr>
    </table>

    @if($summary['total'] > 0)
        <table class="grid">
            <tr>
                @foreach([
                    'By status' => ['rows' => $report->byStatus(), 'rate' => false],
                    'By assignee' => ['rows' => $report->byAssignee(), 'rate' => true],
                ] as $heading => $block)
                    <td>
                        <h2>{{ $heading }}</h2>
                        <table class="breakdown">
                            @foreach($block['rows'] as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td class="n">{{ $row['count'] }}</td>
                                    <td class="n muted">@if($block['rate']){{ $row['rate'] }}%@endif</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                @endforeach
            </tr>
            <tr>
                @foreach([
                    'By project' => $report->byProject(),
                    'By priority' => $report->byPriority(),
                ] as $heading => $rows)
                    <td>
                        <h2>{{ $heading }}</h2>
                        <table class="breakdown">
                            @foreach($rows as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td class="n">{{ $row['count'] }}</td>
                                    <td class="n muted">{{ $row['rate'] }}%</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    <h2>Tasks ({{ $summary['total'] }})</h2>
    @if($report->tasks()->isEmpty())
        <p class="muted">No tasks match these filters.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    <th>Key</th><th>Title</th><th>Project</th><th>Assignee</th>
                    <th>Status</th><th>Priority</th><th>Due</th><th>Est.</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report->tasks() as $task)
                    @php
                        $overdue = $task->due_date && ! $task->isCompleted()
                            && \Carbon\Carbon::parse($task->due_date)->isPast();
                    @endphp
                    <tr>
                        <td class="key">TASK-{{ str_pad($task->id, 4, '0', STR_PAD_LEFT) }}</td>
                        <td>{{ $task->title }}</td>
                        <td>{{ $task->project?->name ?? '—' }}</td>
                        <td>{{ $task->user?->name ?? '—' }}</td>
                        <td>{{ $task->status_label }}</td>
                        <td>{{ ucfirst($task->priority) }}</td>
                        <td class="{{ $overdue ? 'danger' : '' }}">
                            {{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('d M Y') : '—' }}
                        </td>
                        <td>{{ $task->estimated_hours ? rtrim(rtrim((string) $task->estimated_hours, '0'), '.').'h' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="foot">{{ $brandName }} &middot; {{ $summary['total'] }} tasks &middot; {{ now()->format('d M Y') }}</p>
</body>
</html>
