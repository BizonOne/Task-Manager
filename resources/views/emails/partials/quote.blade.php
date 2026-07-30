{{--
    Quoted user-written text (a comment), attributed to its author.

    @param string  $body    the raw comment text
    @param string  $author
    @param ?string $when    human-readable timestamp
--}}
@php $accent = \App\Support\Brand::primaryColor(); @endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 4px;">
    <tr>
        {{-- Coloured left rule --}}
        <td width="3" style="width:3px; background-color:{{ $accent }}; border-radius:2px; font-size:0; line-height:0;">&nbsp;</td>
        <td style="padding:2px 0 2px 14px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            <div style="font-size:13px; font-weight:700; color:#111827; margin-bottom:4px;">
                {{ $author }}@isset($when)<span style="font-weight:400; color:#9ca3af;"> &middot; {{ $when }}</span>@endisset
            </div>
            {{-- nl2br so multi-line comments keep their shape; e() first, so the
                 comment body can never inject markup into the email. --}}
            <div style="font-size:15px; line-height:1.6; color:#374151; white-space:normal;">
                {!! nl2br(e($body)) !!}
            </div>
        </td>
    </tr>
</table>
