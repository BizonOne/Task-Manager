{{--
    The recipient was added to a project team.

    Tells them what the project is, what their role allows, and how much work is
    already on the board — with links to both the project and its task board.
--}}
@component('emails.layout', [
    'heading' => 'You were added to '.$project->name,
    'preview' => $author->name.' added you to the project '.$project->name,
    'footerNote' => 'You received this because you were added to this project.',
])
    <p style="margin:0 0 4px;">Hi {{ $recipient->name }},</p>

    <p style="margin:12px 0 0;">
        <strong>{{ $author->name }}</strong> added you to
        <a href="{{ $projectUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }}; text-decoration:underline;">{{ $project->name }}</a>.
    </p>

    @if(filled($project->description))
        <p style="margin:14px 0 0; padding:12px 14px; background-color:#f9fafb; border:1px solid #eef0f3; border-radius:8px; font-size:14px; color:#4b5563;">
            {{ \App\Support\RichText::toText($project->description, 320) }}
        </p>
    @endif

    @include('emails.partials.details', ['rows' => $rows])

    @include('emails.partials.button', ['url' => $boardUrl, 'label' => 'Open the task board'])

    <p style="margin:14px 0 0; font-size:13px; color:#6b7280;">
        @if($isManager)
            As a <strong>manager</strong> you can edit the project, add members and manage every task in it.
        @else
            You can see every task in the project, comment on them and move your own along.
        @endif
        Project overview:
        <a href="{{ $projectUrl }}" style="color:{{ \App\Support\Brand::primaryColor() }};">{{ $project->name }}</a>.
    </p>
@endcomponent
