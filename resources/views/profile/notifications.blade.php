@extends('layouts.app')

@section('title', 'Notifications')

@push('styles')
<style>
    .main-content { padding: 14px 16px; background: #f7f8fa; min-height: 100vh; }
    .content-header {
        background: linear-gradient(135deg, var(--primary-600) 0%, var(--primary-700) 100%);
        border-radius: 10px; padding: 12px 18px; color: #fff; margin-bottom: 14px;
        border: 1px solid var(--primary-500); box-shadow: 0 2px 8px rgba(99,102,241,.3);
    }
    .content-title { color: #fff; font-weight: 700; font-size: 17px; margin-bottom: 2px; }
    .content-subtitle { color: rgba(255,255,255,.8); font-size: 12px; margin: 0; }

    .nc-section { background:#fff; border:1px solid #e3e4e8; border-radius:8px; overflow:hidden; margin-bottom:10px; }
    .nc-section-header { display:flex; align-items:center; gap:8px; padding:10px 16px; background:#fafbfc; border-bottom:1px solid #e3e4e8; }
    .nc-section-icon { width:26px; height:26px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:13px; }
    .nc-section-title { font-size:13px; font-weight:700; color:#1a1d23; }
    .nc-section-sub { font-size:11px; color:#8a8f98; margin-left:auto; }
    .nc-section-body { padding:16px; }

    .nc-lead { font-size:13px; color:#6b7280; margin:0 0 14px; line-height:1.55; }

    .nc-channel { border:1px solid #e6e6eb; border-radius:10px; padding:14px; margin-bottom:12px; }
    .nc-channel-head { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .nc-channel-name { font-size:14px; font-weight:700; color:#1a1d23; }
    .nc-badge { font-size:10px; font-weight:700; letter-spacing:.03em; padding:2px 8px; border-radius:10px; text-transform:uppercase; }
    .nc-badge.live { background:#dcfce7; color:#15803d; }
    .nc-badge.off { background:#f3f4f6; color:#6b7280; }
    .nc-badge.waiting { background:#fef3c7; color:#b45309; }
    .nc-meta { font-size:12px; color:#8a8f98; }
    .nc-error { font-size:12px; color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:8px 10px; margin-top:10px; }
    .nc-events { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:6px 16px; margin-top:12px; }
    .nc-event { font-size:12px; color:#4b5563; display:flex; align-items:center; gap:6px; }
    .nc-actions { display:flex; gap:8px; align-items:center; margin-top:12px; flex-wrap:wrap; }

    .nc-btn { border:none; border-radius:6px; padding:7px 14px; font-size:12px; font-weight:600; cursor:pointer; }
    .nc-btn-primary { background:#7c3aed; color:#fff; }
    .nc-btn-primary:hover { background:#6d28d9; }
    .nc-btn-quiet { background:#fff; color:#4b5563; border:1px solid #d3d5db; }
    .nc-btn-danger { background:#fff; color:#dc2626; border:1px solid #fecaca; }
    .nc-connect { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .nc-connect-text { font-size:13px; color:#4b5563; max-width:640px; line-height:1.55; }
    .nc-done { font-size:12px; font-weight:600; color:#15803d; display:flex; align-items:center; gap:6px; white-space:nowrap; }

    .nc-help { margin-top:12px; border:1px solid #e6e6eb; border-radius:8px; background:#fbfbfd; }
    .nc-help > summary { cursor:pointer; padding:9px 12px; font-size:12px; font-weight:600; color:#4b5563; list-style:none; }
    .nc-help > summary::-webkit-details-marker { display:none; }
    .nc-help > summary::before { content:'▸ '; color:#8a8f98; }
    .nc-help[open] > summary::before { content:'▾ '; }
    .nc-help-body { padding:0 12px 12px; }
    .nc-help table { width:100%; border-collapse:collapse; font-size:12px; }
    .nc-help th, .nc-help td { text-align:left; padding:7px 8px; border-top:1px solid #eceef2; vertical-align:top; }
    .nc-help th { color:#8a8f98; font-weight:700; width:150px; white-space:nowrap; }
    .nc-help td { color:#4b5563; line-height:1.5; }
    .nc-help code { background:#f1f2f5; border-radius:4px; padding:1px 5px; font-size:11px; color:#374151; }
</style>
@endpush

@section('content')
<div class="main-content">
    <div class="content-header">
        <h1 class="content-title">Notifications</h1>
        <p class="content-subtitle">Where you hear about your work</p>
    </div>

    @if(session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif
    @error('channel')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

    <div class="nc-section">
        <div class="nc-section-header">
            <span class="nc-section-icon" style="background:#ede9fe;color:#7c3aed;"><i class="bi bi-broadcast"></i></span>
            <span class="nc-section-title">Connected channels</span>
            <span class="nc-section-sub">{{ $channels->count() }} connected</span>
        </div>
        <div class="nc-section-body">
            <p class="nc-lead">
                Everything already goes to the bell and to <strong>{{ $user->email }}</strong>. Anything you connect
                here gets the same news as well — it does not replace your email, so unhooking a channel can never
                make you miss something.
            </p>

            @forelse($channels as $channel)
                @php $muted = (array) $channel->muted_events; @endphp
                <form action="{{ route('profile.notifications.update', $channel) }}" method="POST" class="nc-channel">
                    @csrf
                    @method('PUT')
                    <div class="nc-channel-head">
                        <i class="bi bi-{{ ['telegram' => 'telegram', 'slack' => 'slack', 'webpush' => 'window'][$channel->type] ?? 'bell' }}" style="font-size:18px;color:#7c3aed;"></i>
                        <span class="nc-channel-name">{{ $channel->typeLabel() }}</span>
                        @if(! $channel->verified_at)
                            <span class="nc-badge waiting">Waiting for you</span>
                        @elseif($channel->enabled)
                            <span class="nc-badge live">On</span>
                        @else
                            <span class="nc-badge off">Paused</span>
                        @endif
                        @if($channel->label)<span class="nc-meta">{{ $channel->label }}</span>@endif
                        @if($channel->last_sent_at)<span class="nc-meta">· last message {{ $channel->last_sent_at->diffForHumans() }}</span>@endif
                    </div>

                    @if($channel->last_error)
                        <div class="nc-error">
                            <strong>The last message did not arrive.</strong> {{ $channel->last_error }}
                            @if($channel->type === 'telegram')
                                <br>If you blocked the bot, unblock it and press Send test.
                            @endif
                        </div>
                    @endif

                    @if($channel->verified_at)
                        <label class="nc-event" style="margin-top:12px;font-weight:600;color:#1a1d23;">
                            <input type="hidden" name="enabled" value="0">
                            <input type="checkbox" name="enabled" value="1" @checked($channel->enabled)>
                            Send notifications here
                        </label>

                        <div class="nc-events">
                            @foreach($events as $key => $label)
                                <label class="nc-event">
                                    <input type="checkbox" name="muted_events[]" value="{{ $key }}" @checked(in_array($key, $muted, true))>
                                    Mute: {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p class="nc-meta" style="margin-top:10px;">
                            Press Start in the chat that opened. The link expires
                            {{ $channel->connect_expires_at?->diffForHumans() ?? 'shortly' }}.
                        </p>
                    @endif

                    <div class="nc-actions">
                        @if($channel->verified_at)
                            <button type="submit" class="nc-btn nc-btn-primary">Save</button>
                        @endif
                        @if($channel->verified_at)
                            <button type="submit" class="nc-btn nc-btn-quiet" form="test{{ $channel->id }}">Send test</button>
                        @endif
                        <button type="submit" class="nc-btn nc-btn-danger" form="drop{{ $channel->id }}">Disconnect</button>
                    </div>
                </form>

                {{-- Beside the settings form rather than inside it: a form cannot contain another. --}}
                <form id="test{{ $channel->id }}" action="{{ route('profile.notifications.test', $channel) }}" method="POST">@csrf</form>
                <form id="drop{{ $channel->id }}" action="{{ route('profile.notifications.destroy', $channel) }}" method="POST"
                      onsubmit="return confirm('Stop sending notifications to your {{ $channel->typeLabel() }}?');">
                    @csrf @method('DELETE')
                </form>
            @empty
                <p class="nc-meta" style="margin-bottom:0;">Nothing connected yet.</p>
            @endforelse
        </div>
    </div>

    <div class="nc-section">
        <div class="nc-section-header">
            <span class="nc-section-icon" style="background:#dbeafe;color:#2563eb;"><i class="bi bi-plug"></i></span>
            <span class="nc-section-title">Add a channel</span>
        </div>
        <div class="nc-section-body">
            <div class="nc-connect">
                <div class="nc-connect-text">
                    <strong>Telegram.</strong> Opens our bot with a one-time code already in the Start button —
                    nothing to type. A bot cannot message you until you press Start, which is what makes that press
                    the permission.
                </div>
                @if(in_array('telegram', $connectedTypes, true))
                    <span class="nc-done"><i class="bi bi-check-circle-fill"></i> Connected</span>
                @elseif($telegramReady)
                    <form action="{{ route('profile.notifications.telegram') }}" method="POST">
                        @csrf
                        <button type="submit" class="nc-btn nc-btn-primary">
                            <i class="bi bi-telegram me-1"></i>Connect Telegram
                        </button>
                    </form>
                @else
                    <span class="nc-meta">Not configured yet.</span>
                @endif
            </div>

            <hr style="margin:16px 0;border-color:#eef0f3;">

            <div class="nc-connect">
                <div class="nc-connect-text">
                    <strong>This browser.</strong> Notifications appear on your desktop even with the tab closed,
                    as long as the browser is running. You allow it per browser and per machine — your laptop and
                    your phone are two separate answers.
                    <br><span class="nc-meta">On iPhone and iPad this only works once the site is added to the
                    home screen; that is Apple's rule, not ours.</span>
                </div>
                @if($webPushReady)
                    {{-- Not hidden just because some browser is subscribed: a
                         subscription belongs to one browser on one machine, and
                         hiding it here would strand somebody on their second
                         laptop. Which browser this is is a question only the
                         browser can answer, so it does, below. --}}
                    <button type="button" class="nc-btn nc-btn-primary" id="ncEnableBrowser">
                        <i class="bi bi-window me-1"></i>Enable in this browser
                    </button>
                    <span class="nc-done" id="ncBrowserDone" hidden><i class="bi bi-check-circle-fill"></i> Enabled in this browser</span>
                @else
                    <span class="nc-meta">Not configured yet.</span>
                @endif
            </div>
            <p id="ncBrowserNote" class="nc-meta" style="margin:10px 0 0;"></p>

            {{-- Written out per browser on purpose. "Allow it in your browser
                 settings" is true and useless; the menu is in a different place
                 in every one of them, and somebody who has to go and ask has
                 already given up. --}}
            <details class="nc-help">
                <summary>Nothing arrives, or the button says this browser is blocking it — how to fix it</summary>
                <div class="nc-help-body">
                    <table>
                        <tr>
                            <th>Chrome / Edge</th>
                            <td>
                                Click the padlock (or the sliders icon) left of the address → <strong>Notifications</strong> → Allow.
                                Or open <code>chrome://settings/content/notifications</code>, find <code>{{ request()->getHost() }}</code>
                                under the blocked list and remove it. Then reload this page.
                            </td>
                        </tr>
                        <tr>
                            <th>Firefox</th>
                            <td>Padlock left of the address → <strong>Permissions</strong> → clear the block on “Receive Notifications”, then reload.</td>
                        </tr>
                        <tr>
                            <th>Safari (Mac)</th>
                            <td>Safari → Settings → <strong>Websites</strong> → Notifications → find <code>{{ request()->getHost() }}</code> → Allow.</td>
                        </tr>
                        <tr>
                            <th>iPhone / iPad</th>
                            <td>Share → <strong>Add to Home Screen</strong>, then open the app from the home screen and press Enable there. Safari in a normal tab cannot do this — Apple's rule.</td>
                        </tr>
                        <tr>
                            <th>Still nothing</th>
                            <td>
                                Check the computer itself: notifications for the browser may be off
                                (Mac: System Settings → Notifications; Windows: Settings → System → Notifications),
                                or Do Not Disturb / Focus may be on. A private window cannot keep a subscription at all.
                            </td>
                        </tr>
                    </table>
                </div>
            </details>

            <hr style="margin:16px 0;border-color:#eef0f3;">

            <div class="nc-connect">
                <div class="nc-connect-text">
                    <strong>Slack.</strong> Our bot writes to you directly. We look you up in the workspace by
                    your email — nothing to authorise, nothing to paste.
                </div>
                @if(in_array('slack', $connectedTypes, true))
                    <span class="nc-done"><i class="bi bi-check-circle-fill"></i> Connected</span>
                @elseif($slackReady)
                    <form action="{{ route('profile.notifications.slack') }}" method="POST">
                        @csrf
                        <button type="submit" class="nc-btn nc-btn-primary">
                            <i class="bi bi-slack me-1"></i>Connect Slack
                        </button>
                    </form>
                @else
                    <span class="nc-meta">Not configured yet.</span>
                @endif
            </div>

            @if($slackReady && ! in_array('slack', $connectedTypes, true))
                @error('slack')<div class="nc-error" style="margin-top:10px;">{{ $message }}</div>@enderror

                {{-- The fallback that makes the lookup honest: plenty of people
                     are in Slack under a personal address, and "not found" with
                     no way forward is a dead end. --}}
                <details class="nc-help" @if($errors->has('slack')) open @endif>
                    <summary>My Slack uses a different email address</summary>
                    <div class="nc-help-body">
                        <form action="{{ route('profile.notifications.slack') }}" method="POST"
                              style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            @csrf
                            <input type="email" name="email" class="form-control" style="max-width:320px;height:34px;font-size:13px;"
                                   placeholder="the address your Slack account uses" value="{{ old('email') }}" required>
                            <button type="submit" class="nc-btn nc-btn-quiet">Look me up by this address</button>
                        </form>
                    </div>
                </details>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const button = document.getElementById('ncEnableBrowser');
    const note = document.getElementById('ncBrowserNote');
    if (!button) return;

    const say = (text) => { if (note) note.textContent = text; };

    // Every one of these is a real way it can fail, and a button that does
    // nothing is worse than one that says why.
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        button.disabled = true;
        say('This browser does not support push notifications. On iPhone, add the site to your home screen first.');
        return;
    }

    if (!window.isSecureContext) {
        button.disabled = true;
        say('Push notifications need a secure connection.');
        return;
    }

    // Say it before the button is pressed, not after. A browser that has
    // already been told "block" answers "denied" instantly without asking
    // anybody anything, so pressing the button can only ever fail — and
    // finding that out by pressing it teaches nothing about how to fix it.
    const blockedAdvice = 'This browser is blocking notifications for this site, so it will not even ask. '
        + 'Click the padlock next to the address → Notifications → Allow, then reload this page. '
        + 'If that is already allowed, check your operating system: notifications for the browser itself '
        + 'may be switched off, or Do Not Disturb / Focus may be on.';

    function reflectPermission() {
        if (Notification.permission === 'denied') {
            button.disabled = true;
            say(blockedAdvice);
            return true;
        }

        if (Notification.permission === 'granted') {
            say('This browser has already allowed notifications. Press the button to subscribe it.');
        }

        return false;
    }

    if (reflectPermission()) return;

    // Is it *this* browser that is already subscribed? Only this browser can
    // say — the server knows a list of endpoints but not which one is us.
    const knownEndpoints = @json($subscribedEndpoints ?? []);
    const done = document.getElementById('ncBrowserDone');

    (async function () {
        if (!knownEndpoints.length) return;

        try {
            const registration = await navigator.serviceWorker.getRegistration();
            const existing = await registration?.pushManager.getSubscription();

            if (existing && knownEndpoints.includes(existing.endpoint)) {
                button.hidden = true;
                if (done) done.hidden = false;
                say('');
            }
        } catch (e) {
            // Nothing to say: the button simply stays offered.
        }
    })();

    // The key arrives base64url and the browser wants raw bytes.
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
    }

    button.addEventListener('click', async function () {
        button.disabled = true;
        say('Waiting for you to allow notifications…');

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                if (permission === 'denied') {
                    say(blockedAdvice);
                    return;
                }

                say('Nothing chosen yet — press the button and answer the browser prompt.');
                button.disabled = false;
                return;
            }

            const registration = await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(@json($vapidPublicKey)),
            });

            const payload = subscription.toJSON();

            const response = await fetch(@json(route('profile.notifications.browser')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({
                    endpoint: payload.endpoint,
                    keys: payload.keys,
                    encoding: (PushManager.supportedContentEncodings || ['aesgcm'])[0],
                    label: navigator.userAgent.match(/Chrome|Firefox|Safari|Edg/)?.[0] || 'This browser',
                }),
            });

            if (!response.ok) throw new Error('The server would not take the subscription.');

            say('Allowed. Reloading…');
            window.location.reload();
        } catch (error) {
            say('It did not work: ' + error.message);
            button.disabled = false;
        }
    });
})();
</script>
@endpush
