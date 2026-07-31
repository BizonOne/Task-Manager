{{--
    Someone @mentioned the recipient in a task discussion.

    The whole point is that the employee can tell *what was said, where and by
    whom* without opening the app — so the comment is quoted in full and the
    task/project context sits right underneath.
--}}
@component('emails.layout', [
    'heading' => $author->name.' mentioned you in a comment',
    'preview' => $author->name.' on "'.$task->title.'": '.\App\Support\RichText::toText($comment->body, 90),
    'footerNote' => 'You received this because you were mentioned in this discussion.',
])
    <p style="margin:0 0 4px;">Hi {{ $recipient->name }},</p>

    <p style="margin:12px 0 0;">
        <strong>{{ $author->name }}</strong> mentioned you in the discussion on
        <a href="{{ $taskUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $task->title }}</a>@if($project) in <a href="{{ $projectUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $project->name }}</a>@endif.
    </p>

    @include('emails.partials.quote', [
        'body' => $comment->body,
        'html' => true,
        'author' => $author->name,
        'when' => $comment->created_at?->diffForHumans(),
    ])

    @include('emails.partials.details', ['rows' => $rows])

    @include('emails.partials.button', ['url' => $taskUrl, 'label' => 'Reply in the discussion'])

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
