{{-- Where a person lands when their consent click arrived from a page this
     session has never rendered — a resubmitted old tab, an expired session.
     The flow cannot be finished from here; the way out is to start it again
     where it started. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start over | {{ \App\Support\Brand::name() }}</title>
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
            padding: 28px; text-align: center;
        }
        .icon {
            width: 52px; height: 52px; border-radius: 14px; background: #fffbeb;
            color: #d97706; font-size: 24px; display: flex; align-items: center;
            justify-content: center; margin: 0 auto 16px;
        }
        h1 { font-size: 17px; color: #0f172a; margin-bottom: 10px; }
        p { font-size: 13.5px; line-height: 1.65; color: #475569; margin-bottom: 8px; }
        .fine { font-size: 11.5px; color: #94a3b8; margin-top: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">&#9203;</div>
        <h1>This authorization attempt has expired</h1>
        <p>The page you pressed the button on was from an earlier attempt, so it can no longer finish the connection.</p>
        <p><strong>Close this tab</strong>, go back to the app you are connecting — claude.ai, Claude Code — and start the connection again. The fresh attempt will bring you straight back to a working Authorize button.</p>
        <p class="fine">Nothing was granted; no access changed hands.</p>
    </div>
</body>
</html>
