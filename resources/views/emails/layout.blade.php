{{--
    Branded email shell.

    Email clients are not browsers: no external stylesheets, no flexbox/grid,
    and <style> is stripped by several of them. So this uses tables and inline
    styles only, with a max width of 600px.

    Slots: $heading, $preview (inbox preview text), $slot (body), $footerNote.
--}}
@php
    $brandName  = \App\Support\Brand::name();
    $brandColor = \App\Support\Brand::primaryColor();
    $brandDark  = \App\Support\Brand::darken($brandColor, 0.18);
    $logo       = \App\Support\Brand::emailLogoUrl();
    $appUrl     = url('/');
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <title>{{ $heading ?? $brandName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f7; -webkit-font-smoothing:antialiased;">

    {{-- Inbox preview line: shown in the list, hidden in the opened email. --}}
    @isset($preview)
        <div style="display:none; font-size:1px; color:#f4f4f7; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
            {{ $preview }}
        </div>
    @endisset

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f7;">
        <tr>
            <td align="center" style="padding:24px 12px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">

                    {{-- Brand header --}}
                    <tr>
                        <td align="center" style="padding:0 0 20px;">
                            <a href="{{ $appUrl }}" style="text-decoration:none;">
                                @if($logo)
                                    <img src="{{ $logo }}" alt="{{ $brandName }}" width="44"
                                         style="display:block; width:44px; height:auto; border:0; margin:0 auto 8px;">
                                @endif
                                <span style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:17px; font-weight:700; color:{{ $brandDark }}; letter-spacing:-0.2px;">
                                    {{ $brandName }}
                                </span>
                            </a>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background-color:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">

                            {{-- Coloured accent bar --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr><td style="height:4px; background-color:{{ $brandColor }}; line-height:4px; font-size:0;">&nbsp;</td></tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:28px 32px 32px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.6; color:#374151;">

                                        @isset($heading)
                                            <h1 style="margin:0 0 16px; font-size:20px; line-height:1.35; font-weight:700; color:#111827;">
                                                {{ $heading }}
                                            </h1>
                                        @endisset

                                        {{ $slot }}

                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:18px 8px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#9ca3af; text-align:center;">
                            @isset($footerNote)
                                <p style="margin:0 0 6px;">{{ $footerNote }}</p>
                            @endisset
                            <p style="margin:0;">
                                <a href="{{ $appUrl }}" style="color:{{ $brandColor }}; text-decoration:none;">{{ $brandName }}</a>
                                &middot; &copy; {{ date('Y') }}
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
