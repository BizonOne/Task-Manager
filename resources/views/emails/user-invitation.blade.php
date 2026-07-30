{{--
    Invitation to join the workspace. The recipient has no account yet, so this
    email is the only route in — the button is the whole point.
--}}
@component('emails.layout', [
    'heading' => 'You’re invited to join '.$brandName,
    'preview' => ($author?->name ? $author->name.' invited you to join '.$brandName : 'Your invitation to '.$brandName),
    'footerNote' => 'If you weren’t expecting this invitation you can safely ignore this email.',
])
    <p style="margin:0 0 4px;">Hi {{ $recipient->name }},</p>

    <p style="margin:12px 0 0;">
        @if($author)
            <strong>{{ $author->name }}</strong> invited you to join <strong>{{ $brandName }}</strong>.
        @else
            You’ve been invited to join <strong>{{ $brandName }}</strong>.
        @endif
        Choose your own password to activate your account — nobody else knows it.
    </p>

    @include('emails.partials.details', ['rows' => $rows])

    @include('emails.partials.button', ['url' => $acceptUrl, 'label' => 'Accept the invitation'])

    <p style="margin:14px 0 0; font-size:13px; color:#6b7280;">
        This link is personal to you and works once — please don’t forward it.
        Once you’re in, you’ll see the projects and tasks you’ve been added to.
    </p>

    <p style="margin:16px 0 0; font-size:12px; color:#9ca3af; word-break:break-all;">
        If the button doesn’t work, paste this into your browser:<br>
        {{ $acceptUrl }}
    </p>
@endcomponent
