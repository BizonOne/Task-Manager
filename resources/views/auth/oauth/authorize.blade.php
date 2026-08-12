{{-- The consent screen an OAuth client (claude.ai, Claude Code) lands a
     person on. One question, plainly put: should this thing act as you? --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize | {{ \App\Support\Brand::name() }}</title>
    <link rel="shortcut icon" href="{{ \App\Support\Brand::faviconUrl() ?? asset('assets/img/logo-circle.png') }}" type="image/x-icon">
    <style>
        * { box-sizing: border-box; margin: 0; }
        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f1f5f9; padding: 16px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: #334155;
        }
        .card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08); max-width: 460px; width: 100%;
            padding: 28px;
        }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .brand img { height: 34px; }
        .brand span { font-weight: 700; font-size: 16px; color: #0f172a; }
        h1 { font-size: 17px; color: #0f172a; margin-bottom: 10px; }
        p { font-size: 13.5px; line-height: 1.6; margin-bottom: 10px; }
        .who {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 10px 12px; font-size: 13px; margin: 14px 0;
        }
        .who strong { color: #0f172a; }
        ul { margin: 0 0 14px 18px; font-size: 13px; line-height: 1.7; color: #475569; }
        .actions { display: flex; gap: 10px; margin-top: 18px; }
        .actions form { flex: 1; }
        button {
            width: 100%; padding: 10px 14px; border-radius: 8px; font-size: 14px;
            font-weight: 600; cursor: pointer; border: 1px solid transparent;
        }
        .approve { background: #7c3aed; color: #fff; }
        .approve:hover { background: #6d28d9; }
        .deny { background: #fff; color: #475569; border-color: #cbd5e1; }
        .deny:hover { border-color: #94a3b8; }
        .fine { font-size: 11.5px; color: #94a3b8; margin-top: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <img src="{{ \App\Support\Brand::logoUrl() ?? asset('assets/img/logo-circle.png') }}" alt="">
            <span>{{ \App\Support\Brand::name() }}</span>
        </div>

        <h1>{{ $client->name }} wants to work as you</h1>

        <div class="who">Signed in as <strong>{{ $user->name }}</strong> ({{ $user->email }})</div>

        <p>If you authorize it, this application will be able to:</p>
        <ul>
            <li>read the tasks and projects you can see</li>
            <li>post comments and move tasks between statuses — under your name</li>
            <li>read attachments on those tasks</li>
        </ul>
        <p>It will never see more than you do, and everything it writes lands in the task history as you.</p>

        <div class="actions">
            <form method="post" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="deny">Cancel</button>
            </form>
            <form method="post" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="approve">Authorize</button>
            </form>
        </div>

        <p class="fine">You can revoke this at any time from Profile → AI agents.</p>
    </div>
</body>
</html>
