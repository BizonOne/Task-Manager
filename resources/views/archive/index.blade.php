@extends('layouts.app')

@section('title', 'Archive')

@push('styles')
<style>
.ar-wrap { padding: 14px 16px 40px; }

.ar-header {
    background: linear-gradient(135deg, #475569 0%, #1e293b 100%);
    border-radius: 14px; padding: 18px 24px; color: #fff; margin-bottom: 18px;
    position: relative; overflow: hidden; box-shadow: 0 4px 20px rgba(30,41,59,.3);
}
.ar-header::before {
    content:''; position:absolute; top:-30px; right:-30px;
    width:120px; height:120px; background:rgba(255,255,255,.07); border-radius:50%;
}
.ar-title { font-size: 20px; font-weight: 700; margin: 0; z-index: 1; position: relative; }
.ar-sub { font-size: 12px; opacity: .85; margin: 3px 0 0; z-index: 1; position: relative; }

.ar-card {
    background: #fff; border-radius: 14px; border: 1px solid #e5e7eb;
    box-shadow: 0 1px 4px rgba(0,0,0,.06); margin-bottom: 16px; overflow: hidden;
}
.ar-card-header {
    padding: 13px 18px; border-bottom: 1px solid #f3f4f6;
    font-size: 14px; font-weight: 700; color: #111827;
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.ar-card-header i { color: #64748b; }
.ar-card-body { padding: 18px; }

.ar-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr)); gap: 12px; }
.ar-field label {
    display: block; font-size: 10px; font-weight: 700; color: #9ca3af;
    text-transform: uppercase; letter-spacing: .05em; margin-bottom: 5px;
}
.ar-field select, .ar-field input {
    width: 100%; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 8px 10px; font-size: 13px; color: #374151; background: #fff; font-family: inherit;
}
.ar-field select[multiple] { min-height: 92px; padding: 6px; }
.ar-field select:focus, .ar-field input:focus { outline: none; border-color: #64748b; }
.ar-hint { font-size: 10px; color: #b0b4be; margin-top: 3px; display: block; }
.ar-filter-foot { display: flex; gap: 8px; margin-top: 14px; }
.ar-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px;
    border-radius: 8px; font-size: 13px; font-weight: 600; border: none;
    cursor: pointer; text-decoration: none; transition: all .2s;
}
.ar-btn.primary { background: #475569; color: #fff; }
.ar-btn.primary:hover { background: #334155; color: #fff; }
.ar-btn.ghost { background: #f3f4f6; color: #6b7280; }
.ar-btn.ghost:hover { background: #e5e7eb; color: #374151; }

.ar-table-wrap { overflow-x: auto; }
.ar-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ar-table th {
    text-align: left; padding: 9px 12px; background: #f9fafb; color: #6b7280;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    border-bottom: 1px solid #e5e7eb; white-space: nowrap;
}
.ar-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; color: #374151; vertical-align: middle; }
.ar-table tr:last-child td { border-bottom: none; }
.ar-table tr:hover td { background: #fafafa; }
.ar-key { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; color: #9ca3af; white-space: nowrap; }
.ar-task-link { color: #111827; font-weight: 600; text-decoration: none; }
.ar-task-link:hover { color: #475569; text-decoration: underline; }
.ar-chip {
    display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px;
    border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap;
}
.ar-dot { width: 6px; height: 6px; border-radius: 50%; }
.ar-restore {
    background: none; border: 1px solid #e5e7eb; border-radius: 7px; cursor: pointer;
    padding: 5px 10px; font-size: 12px; font-weight: 600; color: #475569;
    display: inline-flex; align-items: center; gap: 5px;
}
.ar-restore:hover { background: #f1f5f9; border-color: #cbd5e1; }

.ar-empty { text-align: center; padding: 60px 20px; color: #9ca3af; }
.ar-empty i { font-size: 40px; display: block; margin-bottom: 12px; }
</style>
@endpush

@section('content')
<div class="ar-wrap">
    <div class="ar-header">
        <h1 class="ar-title"><i class="bi bi-archive"></i> Archive</h1>
        <p class="ar-sub">
            {{ $report->describe() }}
            @if($afterDays)
                &middot; finished tasks move here after {{ $afterDays }} days
            @else
                &middot; automatic archiving is off — tasks move here only when someone files them
            @endif
        </p>
    </div>

    <div class="ar-card">
        <div class="ar-card-header"><span><i class="bi bi-funnel"></i> Filters</span></div>
        <div class="ar-card-body">
            <form method="GET" action="{{ route('archive.index') }}">
                <div class="ar-filters">
                    <div class="ar-field">
                        <label for="project_id">Project</label>
                        <select name="project_id[]" id="project_id" multiple>
                            @foreach($projects as $project)
                                <option value="{{ $project->id }}" @selected(in_array((string) $project->id, array_map('strval', $report->filter('project_id')), true))>{{ $project->name }}</option>
                            @endforeach
                        </select>
                        <span class="ar-hint">Ctrl/Cmd-click for more than one</span>
                    </div>

                    <div class="ar-field">
                        <label for="user_id">Assignee</label>
                        <select name="user_id[]" id="user_id" multiple>
                            @foreach($people as $person)
                                <option value="{{ $person->id }}" @selected(in_array((string) $person->id, array_map('strval', $report->filter('user_id')), true))>{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ar-field">
                        <label for="priority">Priority</label>
                        <select name="priority[]" id="priority" multiple>
                            @foreach(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $key => $label)
                                <option value="{{ $key }}" @selected(in_array($key, $report->filter('priority'), true))>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ar-field">
                        <label for="date_field">Date range on</label>
                        <select name="date_field" id="date_field">
                            @foreach($dateFields as $key => $label)
                                <option value="{{ $key }}" @selected($report->filter('date_field') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ar-field">
                        <label for="from">From</label>
                        <input type="date" name="from" id="from" value="{{ $report->filter('from') }}">
                    </div>

                    <div class="ar-field">
                        <label for="to">To</label>
                        <input type="date" name="to" id="to" value="{{ $report->filter('to') }}">
                    </div>

                    <div class="ar-field">
                        <label for="search">Title contains</label>
                        <input type="text" name="search" id="search" value="{{ $report->filter('search') }}" placeholder="invoice">
                    </div>
                </div>

                <div class="ar-filter-foot">
                    <button type="submit" class="ar-btn primary"><i class="bi bi-search"></i> Search the archive</button>
                    <a href="{{ route('archive.index') }}" class="ar-btn ghost">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="ar-card">
        <div class="ar-card-header">
            <span><i class="bi bi-inbox"></i> {{ $tasks->count() }} archived {{ Str::plural('task', $tasks->count()) }}</span>
        </div>

        @if($tasks->isEmpty())
            <div class="ar-empty">
                <i class="bi bi-archive"></i>
                <p style="margin:0; font-size:14px;">
                    {{ $report->isFiltered() ? 'Nothing in the archive matches those filters.' : 'The archive is empty.' }}
                </p>
            </div>
        @else
            <div class="ar-table-wrap">
                <table class="ar-table">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Task</th>
                            <th>Project</th>
                            <th>Assignee</th>
                            <th>Status</th>
                            <th>Completed</th>
                            <th>Archived</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tasks as $task)
                            @php $palette = \App\Models\TaskStatus::paletteFor($task->status, $task->project_id); @endphp
                            <tr>
                                <td class="ar-key">TASK-{{ str_pad($task->id, 4, '0', STR_PAD_LEFT) }}</td>
                                <td>
                                    <a href="{{ route('tasks.show', $task) }}" class="ar-task-link">{{ $task->title }}</a>
                                </td>
                                <td>{{ $task->project?->name ?? '—' }}</td>
                                <td>{{ $task->user?->name ?? '—' }}</td>
                                <td>
                                    <span class="ar-chip" style="background:{{ $palette['bg'] }};color:{{ $palette['text'] }};">
                                        <span class="ar-dot" style="background:{{ $palette['dot'] }};"></span>
                                        {{ $task->status_label }}
                                    </span>
                                </td>
                                <td>{{ \App\Support\Dates::dateTime($task->completed_at) ?? '—' }}</td>
                                <td>{{ \App\Support\Dates::dateTime($task->archived_at) ?? '—' }}</td>
                                <td style="text-align:right;">
                                    @if($task->isManageableBy(auth()->user()))
                                        <form method="POST" action="{{ route('tasks.unarchive', $task) }}" style="margin:0;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="ar-restore" title="Put this task back on the board">
                                                <i class="bi bi-box-arrow-up"></i> Restore
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <p style="font-size:12px; color:#9ca3af; margin:0 2px;">
        Archived tasks keep their discussion, files and links, and still count in
        <a href="{{ route('reports.index') }}" style="color:#64748b;">reports</a>.
    </p>
</div>
@endsection
