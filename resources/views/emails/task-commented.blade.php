{{--
    A new comment in a discussion the recipient is part of.

    Same shape as the mention email, one step quieter: nobody called this
    person out by name, so the wording says what happened rather than who is
    being asked for something.
--}}
@php $hasText = ! \App\Support\RichText::isEmpty($comment->body); @endphp
@component('emails.layout', [
    'heading' => $author->name.' commented on "'.$task->title.'"',
    'preview' => $hasText
        ? $author->name.': '.\App\Support\RichText::toText($comment->body, 90)
        : $author->name.' attached a file to "'.$task->title.'"',
    'footerNote' => 'You received this because you are working on this task.',
])
    <p style="margin:0 0 4px;">Hi {{ $recipient->name }},</p>

    <p style="margin:12px 0 0;">
        <strong>{{ $author->name }}</strong> posted in the discussion on
        <a href="{{ $taskUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $task->title }}</a>@if($project) in <a href="{{ $projectUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $project->name }}</a>@endif.
    </p>

    @if($hasText)
        @include('emails.partials.quote', [
            'body' => $comment->body,
            'html' => true,
            'author' => $author->name,
            'when' => $comment->created_at?->diffForHumans(),
        ])
    @else
        {{-- A comment is allowed to be nothing but an attachment. Quoting an
             empty body would render a blank box that reads as a broken email. --}}
        <p style="margin:16px 0 0; font-size:14px; color:#6b7280;">
            <i>They attached a file — open the task to see it.</i>
        </p>
    @endif

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
