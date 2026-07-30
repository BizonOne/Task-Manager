{{--
    Label/value detail rows — the "what, where and how" of the notification.

    @param array<int, array{label: string, value: ?string, url?: ?string}> $rows
      Rows whose value is null or '' are skipped, so callers can pass optional
      fields (due date, estimate) without guarding each one.
--}}
@php
    $rows = collect($rows)->filter(fn ($r) => ($r['value'] ?? null) !== null && $r['value'] !== '')->values();
    $linkColor = \App\Support\Brand::primaryColor();
@endphp
@if($rows->isNotEmpty())
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="margin:20px 0 4px; background-color:#f9fafb; border:1px solid #eef0f3; border-radius:10px;">
        @foreach($rows as $i => $row)
            <tr>
                <td style="padding:{{ $i === 0 ? '14px' : '10px' }} 16px {{ $loop->last ? '14px' : '10px' }}; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:11px; font-weight:700; letter-spacing:0.4px; text-transform:uppercase; color:#9ca3af; white-space:nowrap; vertical-align:top; width:34%;">
                    {{ $row['label'] }}
                </td>
                <td style="padding:{{ $i === 0 ? '14px' : '10px' }} 16px {{ $loop->last ? '14px' : '10px' }} 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; color:#111827; vertical-align:top;">
                    @if(! empty($row['url']))
                        <a href="{{ $row['url'] }}" style="color:{{ $linkColor }}; text-decoration:underline;">{{ $row['value'] }}</a>
                    @else
                        {{ $row['value'] }}
                    @endif
                </td>
            </tr>
        @endforeach
    </table>
@endif
