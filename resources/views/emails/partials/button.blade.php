{{--
    Primary call-to-action button. Rendered as a table so Outlook honours the
    padding and background colour.

    @param string $url
    @param string $label
--}}
@php $btnColor = \App\Support\Brand::primaryColor(); @endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 8px;">
    <tr>
        <td align="center" style="background-color:{{ $btnColor }}; border-radius:8px;">
            <a href="{{ $url }}"
               style="display:inline-block; padding:12px 26px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:8px;">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>
