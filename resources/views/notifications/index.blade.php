@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
<div style="max-width: 760px; margin: 0 auto; padding: 8px 4px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:18px;">
        <h1 style="font-size:22px; font-weight:700; color:#111827; margin:0;">
            <i class="bi bi-bell" style="color:#7c3aed; margin-right:6px;"></i> Notifications
        </h1>
        @if(auth()->user()->unreadNotifications->count())
        <form method="POST" action="{{ route('notifications.readAll') }}">
            @csrf
            <button type="submit" style="background:#7c3aed; color:#fff; border:none; border-radius:8px; padding:8px 14px; font-size:13px; font-weight:600; cursor:pointer;">
                Mark all read
            </button>
        </form>
        @endif
    </div>

    <div style="display:flex; flex-direction:column; gap:8px;">
        @forelse($notifications as $n)
            @php $isUnread = is_null($n->read_at); @endphp
            <a href="{{ route('notifications.read', $n->id) }}"
               style="display:flex; gap:12px; align-items:flex-start; padding:14px 16px; border-radius:10px; text-decoration:none;
                      background:{{ $isUnread ? '#f5f3ff' : '#fff' }}; border:1px solid {{ $isUnread ? '#ddd6fe' : '#eef0f3' }};">
                <div style="flex-shrink:0; width:36px; height:36px; border-radius:50%; background:{{ $isUnread ? '#7c3aed' : '#e5e7eb' }};
                            color:{{ $isUnread ? '#fff' : '#6b7280' }}; display:flex; align-items:center; justify-content:center; font-size:16px;">
                    <i class="bi {{ ($n->data['type'] ?? '') === 'task_assigned' ? 'bi-person-check' : 'bi-at' }}"></i>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:14px; font-weight:{{ $isUnread ? '600' : '500' }}; color:#1f2937; line-height:1.4;">
                        {{ $n->data['message'] ?? 'Notification' }}
                    </div>
                    @if(!empty($n->data['excerpt']))
                        <div style="font-size:12px; color:#6b7280; margin-top:3px;">“{{ $n->data['excerpt'] }}”</div>
                    @endif
                    <div style="font-size:11px; color:#9ca3af; margin-top:4px;">{{ $n->created_at->diffForHumans() }}</div>
                </div>
                @if($isUnread)
                    <span style="flex-shrink:0; width:8px; height:8px; border-radius:50%; background:#7c3aed; margin-top:6px;"></span>
                @endif
            </a>
        @empty
            <div style="text-align:center; padding:60px 20px; color:#9ca3af;">
                <i class="bi bi-bell-slash" style="font-size:40px; display:block; margin-bottom:12px;"></i>
                <p style="margin:0; font-size:14px;">No notifications yet.</p>
            </div>
        @endforelse
    </div>

    <div style="margin-top:18px;">
        {{ $notifications->links() }}
    </div>
</div>
@endsection
