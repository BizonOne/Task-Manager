{{--
    The recipient was assigned to collaborate on a task.

    Carries the details someone needs to triage the work without opening the
    app: project, status, priority, due date, estimate and who assigned it.
--}}
@component('emails.layout', [
    'heading' => 'You were assigned to a task',
    'preview' => $author->name.' assigned you to "'.$task->title.'"'.($project ? ' in '.$project->name : ''),
    'footerNote' => 'You received this because you were assigned to this task.',
])
    <p style="margin:0 0 4px;">Hi {{ $recipient->name }},</p>

    <p style="margin:12px 0 0;">
        <strong>{{ $author->name }}</strong> assigned you to
        <a href="{{ $taskUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $task->title }}</a>@if($project) in <a href="{{ $projectUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $project->name }}</a>@endif.
    </p>

    @if(filled($task->description))
        <p style="margin:14px 0 0; padding:12px 14px; background-color:#f9fafb; border:1px solid #eef0f3; border-radius:8px; font-size:14px; color:#4b5563;">
            {{ \Illuminate\Support\Str::limit(strip_tags($task->description), 320) }}
        </p>
    @endif

    @include('emails.partials.details', ['rows' => $rows])

    @include('emails.partials.button', ['url' => $taskUrl, 'label' => 'Open the task'])

    <p style="margin:14px 0 0; font-size:13px; color:#6b7280;">
        From there you can change its status, tick off the checklist and comment.
        @if($project)
            The full board is at
            <a href="{{ $boardUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }};">{{ $project->name }}</a>.
        @endif
    </p>
@endcomponent
