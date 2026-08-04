{{--
    A task moved to a different status.

    The person who raised the work is the one waiting to hear this, so the
    headline says what it became rather than that "something changed".
--}}
@component('emails.layout', [
    'heading' => $author->name.' moved "'.$task->title.'" to '.$toLabel,
    'preview' => $fromLabel
        ? $task->title.': '.$fromLabel.' → '.$toLabel
        : $task->title.' is now '.$toLabel,
    'footerNote' => 'You received this because you raised this task or are working on it.',
])
    <p style="margin:0 0 4px;">Hi {{ $recipient->name }},</p>

    <p style="margin:12px 0 0;">
        <strong>{{ $author->name }}</strong> moved
        <a href="{{ $taskUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $task->title }}</a>@if($project) in <a href="{{ $projectUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $project->name }}</a>@endif
        @if($fromLabel)
            from <strong>{{ $fromLabel }}</strong> to <strong>{{ $toLabel }}</strong>.
        @else
            to <strong>{{ $toLabel }}</strong>.
        @endif
    </p>

    @if($isFinished)
        <p style="margin:14px 0 0; font-size:15px; color:#16a34a; font-weight:600;">
            This task is finished.
        </p>
    @endif

    @include('emails.partials.details', ['rows' => $rows])

    @include('emails.partials.button', ['url' => $taskUrl, 'label' => 'Open the task'])

    <p style="margin:14px 0 0; font-size:13px; color:#6b7280;">
        Or open the
        @if($project)
            <a href="{{ $boardUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }};">{{ $project->name }} board</a>
        @else
            <a href="{{ $boardUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }};">task board</a>
        @endif
        to see everything you are working on.
    </p>
@endcomponent
