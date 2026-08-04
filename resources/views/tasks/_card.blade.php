@php
    $overdue = $task->due_date
        && \Carbon\Carbon::parse($task->due_date)->isPast()
        && ! $task->isCompleted();
    $priorityColors = ['high' => '#dc2626', 'medium' => '#d97706', 'low' => '#16a34a'];
    $leftColor = $priorityColors[$task->priority] ?? '#94a3b8';
    // Pipe-wrapped so a filter for "|7|" cannot match user 17.
    $assigneeIds = '|'.$task->assignees->pluck('id')->implode('|').'|';
@endphp
<div class="cu-task-card"
     data-id="{{ $task->id }}"
     data-title="{{ strtolower($task->title) }}"
     data-priority="{{ $task->priority }}"
     data-project="{{ $task->project_id }}"
     data-owner="{{ $task->user_id }}"
     data-creator="{{ $task->created_by }}"
     data-assignees="{{ $assigneeIds }}"
     data-open="{{ route('tasks.show', $task->id) }}"
     draggable="true"
     style="border-left:3px solid {{ $leftColor }};">

    {{-- A real link, so the title is keyboard-reachable and opens in a new tab
         on a middle click. The rest of the card follows it via data-open. --}}
    <a href="{{ route('tasks.show', $task->id) }}" class="cu-task-title">{{ $task->title }}</a>

    @if($task->description)
        <div class="cu-task-desc">{{ \App\Support\RichText::toText($task->description) }}</div>
    @endif

    <div class="cu-task-meta">
        <span class="cu-priority {{ $task->priority }}">{{ ucfirst($task->priority) }}</span>
        @if($task->due_date)
            <span class="cu-due {{ $overdue ? 'overdue' : '' }}">
                <i class="bi bi-calendar-event" style="font-size:10px;"></i>
                {{ \Carbon\Carbon::parse($task->due_date)->format('M d') }}
                @if($overdue)
                    &nbsp;• Overdue
                @endif
            </span>
        @endif
        {{-- The person it is assigned to first, then anyone else on it —
             otherwise filtering by a person shows cards with somebody else's
             initial on them. --}}
        <div class="cu-people" style="margin-left:auto;">
            @if($task->user)
                <div class="cu-assignee" title="{{ $task->user->name }} — assigned">
                    {{ strtoupper(substr($task->user->name, 0, 1)) }}
                </div>
            @endif
            @foreach($task->assignees->take(2) as $person)
                <div class="cu-assignee extra" title="{{ $person->name }}">
                    {{ strtoupper(substr($person->name, 0, 1)) }}
                </div>
            @endforeach
            @if($task->assignees->count() > 2)
                <div class="cu-assignee extra" title="{{ $task->assignees->skip(2)->pluck('name')->implode(', ') }}">
                    +{{ $task->assignees->count() - 2 }}
                </div>
            @endif
        </div>
    </div>

    <div class="cu-task-foot">
        <div class="cu-task-proj">
            @if($task->project)
                <i class="bi bi-folder" style="font-size:10px;"></i>
                {{ $task->project->name }}
            @endif
        </div>
        <div class="cu-task-actions">
            <a href="{{ route('tasks.show', $task->id) }}" class="cu-task-btn" title="View">
                <i class="bi bi-eye"></i>
            </a>
            <a href="{{ route('tasks.edit', $task->id) }}" class="cu-task-btn" title="Edit">
                <i class="bi bi-pencil"></i>
            </a>
        </div>
    </div>
</div>
