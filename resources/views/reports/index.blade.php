@extends('layouts.app')

@section('title', 'Reports')

@push('styles')
<style>
.rp-wrap { padding: 14px 16px 40px; }

.rp-header {
    background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
    border-radius: 14px; padding: 18px 24px; color: #fff; margin-bottom: 18px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    position: relative; overflow: hidden; box-shadow: 0 4px 20px rgba(124,58,237,.35);
}
.rp-header::before {
    content:''; position:absolute; top:-30px; right:-30px;
    width:120px; height:120px; background:rgba(255,255,255,.08); border-radius:50%;
}
.rp-title { font-size: 20px; font-weight: 700; margin: 0; z-index: 1; }
.rp-sub { font-size: 12px; opacity: .85; margin: 3px 0 0; z-index: 1; }
.rp-actions { display: flex; gap: 8px; z-index: 1; flex-shrink: 0; }
.rp-export {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600;
    text-decoration: none; background: rgba(255,255,255,.2); color: #fff;
    transition: background .2s; border: none; cursor: pointer;
}
.rp-export:hover { background: rgba(255,255,255,.32); color: #fff; }

.rp-card {
    background: #fff; border-radius: 14px; border: 1px solid #e5e7eb;
    box-shadow: 0 1px 4px rgba(0,0,0,.06); margin-bottom: 16px; overflow: hidden;
}
.rp-card-header {
    padding: 13px 18px; border-bottom: 1px solid #f3f4f6;
    font-size: 14px; font-weight: 700; color: #111827;
    display: flex; align-items: center; gap: 8px;
}
.rp-card-header i { color: #7c3aed; }
.rp-card-body { padding: 18px; }

/* Filters */
.rp-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr)); gap: 12px; }
.rp-field label {
    display: block; font-size: 10px; font-weight: 700; color: #9ca3af;
    text-transform: uppercase; letter-spacing: .05em; margin-bottom: 5px;
}
.rp-field select, .rp-field input {
    width: 100%; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 8px 10px; font-size: 13px; color: #374151; background: #fff; font-family: inherit;
}
.rp-field select[multiple] { min-height: 92px; padding: 6px; }
.rp-field select:focus, .rp-field input:focus { outline: none; border-color: #7c3aed; }
.rp-hint { font-size: 10px; color: #b0b4be; margin-top: 3px; display: block; }
.rp-filter-foot { display: flex; gap: 8px; margin-top: 14px; }
.rp-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px;
    border-radius: 8px; font-size: 13px; font-weight: 600; border: none;
    cursor: pointer; text-decoration: none; transition: all .2s;
}
.rp-btn.primary { background: #7c3aed; color: #fff; }
.rp-btn.primary:hover { background: #6d28d9; color: #fff; }
.rp-btn.ghost { background: #f3f4f6; color: #6b7280; }
.rp-btn.ghost:hover { background: #e5e7eb; color: #374151; }

/* Tiles */
.rp-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(150px, 100%), 1fr)); gap: 12px; margin-bottom: 16px; }
.rp-tile {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 16px; box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.rp-tile-val { font-size: 26px; font-weight: 800; color: #111827; line-height: 1.1; }
.rp-tile-lbl {
    font-size: 11px; color: #9ca3af; font-weight: 600; margin-top: 5px;
    text-transform: uppercase; letter-spacing: .05em;
}
.rp-tile.danger .rp-tile-val { color: #dc2626; }
.rp-tile.ok .rp-tile-val { color: #16a34a; }

/* Breakdown */
.rp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); gap: 16px; }
.rp-bar-row { margin-bottom: 12px; }
.rp-bar-row:last-child { margin-bottom: 0; }
.rp-bar-head {
    display: flex; justify-content: space-between; align-items: baseline;
    font-size: 13px; color: #374151; margin-bottom: 5px;
}
.rp-bar-name { font-weight: 600; }
.rp-bar-meta { font-size: 11px; color: #9ca3af; }
.rp-bar-track { background: #f3f4f6; border-radius: 999px; height: 8px; overflow: hidden; }
.rp-bar-fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg,#7c3aed,#a78bfa); }

/* Table */
.rp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rp-table th {
    text-align: left; font-size: 10px; font-weight: 700; color: #9ca3af;
    text-transform: uppercase; letter-spacing: .05em;
    padding: 9px 10px; border-bottom: 1px solid #f3f4f6; white-space: nowrap;
}
.rp-table td { padding: 10px; border-bottom: 1px solid #f8f9fa; color: #374151; vertical-align: middle; }
.rp-table tr:hover td { background: #fafbfc; }
.rp-key { font-size: 11px; font-weight: 700; color: #9ca3af; }
.rp-task-link { color: #374151; text-decoration: none; font-weight: 500; }
.rp-task-link:hover { color: #7c3aed; }
.rp-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap;
}
.rp-dot { width: 6px; height: 6px; border-radius: 50%; }
.rp-overdue { color: #dc2626; font-weight: 600; }
.rp-empty { text-align: center; padding: 40px 20px; color: #9ca3af; }
.rp-empty i { font-size: 38px; opacity: .35; display: block; margin-bottom: 10px; }
.rp-scroll { overflow-x: auto; }

@media (max-width: 768px) {
    .rp-header { flex-direction: column; align-items: flex-start; }
}
</style>
@endpush

@section('content')
@php
    $filters = $report->filters();
    // Every export keeps whatever the person is looking at.
    $exportQuery = array_filter($filters, fn ($v) => $v !== null && $v !== [] && $v !== '');
@endphp

<div class="rp-wrap">
    <div class="rp-header">
        <div>
            <h1 class="rp-title">Reports</h1>
            <p class="rp-sub">{{ $report->describe() }}</p>
        </div>
        <div class="rp-actions">
            <a class="rp-export" href="{{ route('reports.export', array_merge(['format' => 'xlsx'], $exportQuery)) }}">
                <i class="bi bi-file-earmark-spreadsheet"></i> Excel
            </a>
            <a class="rp-export" href="{{ route('reports.export', array_merge(['format' => 'pdf'], $exportQuery)) }}">
                <i class="bi bi-file-earmark-pdf"></i> PDF
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="rp-card">
        <div class="rp-card-header"><i class="bi bi-funnel"></i> Filters</div>
        <div class="rp-card-body">
            <form method="GET" action="{{ route('reports.index') }}">
                <div class="rp-filters">
                    <div class="rp-field">
                        <label for="project_id">Project</label>
                        <select name="project_id[]" id="project_id" multiple>
                            @foreach($projects as $project)
                                <option value="{{ $project->id }}" @selected(in_array((string) $project->id, array_map('strval', $filters['project_id']), true))>
                                    {{ $project->name }}
                                </option>
                            @endforeach
                        </select>
                        <span class="rp-hint">Hold Ctrl/Cmd to pick several</span>
                    </div>

                    <div class="rp-field">
                        <label for="user_id">Assignee</label>
                        <select name="user_id[]" id="user_id" multiple>
                            @foreach($people as $person)
                                <option value="{{ $person->id }}" @selected(in_array((string) $person->id, array_map('strval', $filters['user_id']), true))>
                                    {{ $person->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="rp-field">
                        <label for="status">Status</label>
                        <select name="status[]" id="status" multiple>
                            @foreach($statuses as $status)
                                <option value="{{ $status->key }}" @selected(in_array($status->key, $filters['status'], true))>
                                    {{ $status->label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="rp-field">
                        <label for="priority">Priority</label>
                        <select name="priority[]" id="priority" multiple>
                            @foreach(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $value => $label)
                                <option value="{{ $value }}" @selected(in_array($value, $filters['priority'], true))>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="rp-field">
                        <label for="archive">Archive</label>
                        <select name="archive" id="archive">
                            @foreach($archiveStates as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['archive'] ?? 'all') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="rp-hint">Archived work still counts as done</span>
                    </div>

                    <div class="rp-field">
                        <label for="date_field">Date range on</label>
                        <select name="date_field" id="date_field">
                            @foreach($dateFields as $value => $label)
                                <option value="{{ $value }}" @selected($filters['date_field'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="rp-field">
                        <label for="from">From</label>
                        <input type="date" name="from" id="from" value="{{ $filters['from'] }}">
                    </div>

                    <div class="rp-field">
                        <label for="to">To</label>
                        <input type="date" name="to" id="to" value="{{ $filters['to'] }}">
                    </div>

                    <div class="rp-field">
                        <label for="search">Title contains</label>
                        <input type="text" name="search" id="search" value="{{ $filters['search'] }}" placeholder="e.g. invoice">
                    </div>
                </div>

                <div class="rp-filter-foot">
                    <button type="submit" class="rp-btn primary"><i class="bi bi-search"></i> Run report</button>
                    <a href="{{ route('reports.index') }}" class="rp-btn ghost"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Headline numbers --}}
    <div class="rp-tiles">
        <div class="rp-tile">
            <div class="rp-tile-val">{{ $summary['total'] }}</div>
            <div class="rp-tile-lbl">Tasks</div>
        </div>
        <div class="rp-tile ok">
            <div class="rp-tile-val">{{ $summary['completed'] }}</div>
            <div class="rp-tile-lbl">Completed</div>
        </div>
        <div class="rp-tile">
            <div class="rp-tile-val">{{ $summary['open'] }}</div>
            <div class="rp-tile-lbl">Open</div>
        </div>
        <div class="rp-tile {{ $summary['overdue'] > 0 ? 'danger' : '' }}">
            <div class="rp-tile-val">{{ $summary['overdue'] }}</div>
            <div class="rp-tile-lbl">Overdue</div>
        </div>
        <div class="rp-tile">
            <div class="rp-tile-val">{{ $summary['completion_rate'] }}%</div>
            <div class="rp-tile-lbl">Completion</div>
        </div>
        <div class="rp-tile">
            <div class="rp-tile-val">{{ $summary['estimated_hours'] ?: '—' }}</div>
            <div class="rp-tile-lbl">Est. hours</div>
        </div>
    </div>

    @if($summary['total'] > 0)
        <div class="rp-grid">
            @foreach([
                'By status' => ['rows' => $report->byStatus(), 'rate' => false],
                'By assignee' => ['rows' => $report->byAssignee(), 'rate' => true],
                'By project' => ['rows' => $report->byProject(), 'rate' => true],
                'By priority' => ['rows' => $report->byPriority(), 'rate' => true],
            ] as $heading => $block)
                <div class="rp-card">
                    <div class="rp-card-header"><i class="bi bi-bar-chart-line"></i> {{ $heading }}</div>
                    <div class="rp-card-body">
                        @forelse($block['rows'] as $row)
                            <div class="rp-bar-row">
                                <div class="rp-bar-head">
                                    <span class="rp-bar-name">{{ $row['label'] }}</span>
                                    <span class="rp-bar-meta">
                                        {{ $row['count'] }}
                                        @if($block['rate']) &middot; {{ $row['rate'] }}% done @endif
                                    </span>
                                </div>
                                <div class="rp-bar-track">
                                    <div class="rp-bar-fill" style="width: {{ $summary['total'] ? round($row['count'] / $summary['total'] * 100) : 0 }}%"></div>
                                </div>
                            </div>
                        @empty
                            <p style="font-size:13px;color:#9ca3af;margin:0;">Nothing to show.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- The tasks behind the numbers --}}
    <div class="rp-card">
        <div class="rp-card-header">
            <i class="bi bi-list-task"></i> Tasks
            <span style="font-size:12px;color:#9ca3af;font-weight:400;">({{ $summary['total'] }})</span>
        </div>
        <div class="rp-card-body rp-scroll" style="padding-top:6px;">
            @if($report->tasks()->isEmpty())
                <div class="rp-empty">
                    <i class="bi bi-clipboard-x"></i>
                    <p>{{ $report->isFiltered() ? 'No tasks match these filters.' : 'No tasks to report on yet.' }}</p>
                </div>
            @else
                <table class="rp-table">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Title</th>
                            <th>Project</th>
                            <th>Assignee</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Due</th>
                            <th>Est.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($report->tasks()->take(\App\Support\Reports\TaskReport::TABLE_LIMIT) as $task)
                            @php $palette = \App\Models\TaskStatus::paletteFor($task->status, $task->project_id); @endphp
                            <tr>
                                <td class="rp-key">TASK-{{ str_pad($task->id, 4, '0', STR_PAD_LEFT) }}</td>
                                <td>
                                    <a href="{{ route('tasks.show', $task) }}" class="rp-task-link">{{ $task->title }}</a>
                                </td>
                                <td>{{ $task->project?->name ?? '—' }}</td>
                                <td>{{ $task->user?->name ?? '—' }}</td>
                                <td>
                                    <span class="rp-chip" style="background:{{ $palette['bg'] }};color:{{ $palette['text'] }};">
                                        <span class="rp-dot" style="background:{{ $palette['dot'] }};"></span>
                                        {{ $task->status_label }}
                                    </span>
                                </td>
                                <td>{{ ucfirst($task->priority) }}</td>
                                <td>
                                    @if($task->due_date)
                                        @php $overdue = ! $task->isCompleted() && \Carbon\Carbon::parse($task->due_date)->isPast(); @endphp
                                        <span class="{{ $overdue ? 'rp-overdue' : '' }}">
                                            {{ \Carbon\Carbon::parse($task->due_date)->format('d M Y') }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $task->estimated_hours ? rtrim(rtrim((string) $task->estimated_hours, '0'), '.').'h' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if($summary['total'] > \App\Support\Reports\TaskReport::TABLE_LIMIT)
                    <p style="font-size:12px;color:#9ca3af;margin:12px 0 0;text-align:center;">
                        Showing the first {{ \App\Support\Reports\TaskReport::TABLE_LIMIT }} of {{ $summary['total'] }} tasks.
                        The Excel and PDF exports contain all of them.
                    </p>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
