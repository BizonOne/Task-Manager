@extends('layouts.app')

@section('title', 'AI agents')

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

    .ag-section { background:#fff; border:1px solid #e3e4e8; border-radius:8px; overflow:hidden; margin-bottom:10px; }
    .ag-section-header { display:flex; align-items:center; gap:8px; padding:10px 16px; background:#fafbfc; border-bottom:1px solid #e3e4e8; }
    .ag-section-icon { width:26px; height:26px; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:13px; }
    .ag-section-title { font-size:13px; font-weight:700; color:#1a1d23; }
    .ag-section-body { padding:16px; }
    .ag-lead { font-size:13px; color:#6b7280; margin:0 0 14px; line-height:1.55; }

    .ag-fresh {
        background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px;
        padding:12px 14px; margin-bottom:14px;
    }
    .ag-fresh-title { font-size:12px; font-weight:700; color:#166534; margin-bottom:6px; }
    .ag-fresh code {
        display:block; background:#fff; border:1px solid #bbf7d0; border-radius:6px;
        padding:8px 10px; font-size:12px; color:#166534; user-select:all;
        overflow-wrap:anywhere;
    }
    .ag-fresh-note { font-size:11px; color:#15803d; margin-top:6px; }

    .ag-row {
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        border:1px solid #e6e6eb; border-radius:8px; padding:10px 12px; margin-bottom:8px;
    }
    .ag-row-name { font-size:13px; font-weight:600; color:#1a1d23; }
    .ag-row-meta { font-size:11px; color:#8a8f98; }
    .ag-row form { margin-left:auto; }

    .ag-btn { border:none; border-radius:6px; padding:7px 14px; font-size:12px; font-weight:600; cursor:pointer; }
    .ag-btn-primary { background:#7c3aed; color:#fff; }
    .ag-btn-primary:hover { background:#6d28d9; }
    .ag-btn-danger { background:#fff; color:#dc2626; border:1px solid #fecaca; }

    .ag-create { display:flex; gap:8px; flex-wrap:wrap; }
    .ag-create input {
        flex:1; min-width:0; border:1px solid #d3d5db; border-radius:6px;
        padding:7px 10px; font-size:13px;
    }

    .ag-setup td, .ag-setup th { font-size:12px; color:#4b5563; text-align:left; padding:6px 8px; border-top:1px solid #eceef2; vertical-align:top; }
    .ag-setup th { color:#8a8f98; font-weight:700; width:150px; white-space:nowrap; }
    .ag-setup code { background:#f1f2f5; border-radius:4px; padding:1px 5px; font-size:11px; color:#374151; overflow-wrap:anywhere; }
    @media (max-width:640px) {
        .ag-setup, .ag-setup tbody, .ag-setup tr, .ag-setup th, .ag-setup td { display:block; width:auto; }
        .ag-setup tr { border-top:1px solid #eceef2; padding:6px 0; }
        .ag-setup th, .ag-setup td { border-top:none; padding:2px 0; white-space:normal; }
    }
</style>
@endpush

@section('content')
<div class="main-content">
    <div class="content-header">
        <h1 class="content-title">AI agents</h1>
        <p class="content-subtitle">Keys that let an AI assistant read and update your tasks as you</p>
    </div>

    @if(session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="ag-section">
        <div class="ag-section-header">
            <span class="ag-section-icon" style="background:#ede9fe;color:#7c3aed;"><i class="bi bi-robot"></i></span>
            <span class="ag-section-title">Access tokens</span>
        </div>
        <div class="ag-section-body">
            <p class="ag-lead">
                An agent holding a token acts <strong>as you</strong>: it sees the projects you see, and its
                comments and status changes appear under your name in every task history. Give each agent its
                own token, named for where it runs — revoking one signs out only that agent.
            </p>

            @if($freshToken)
                <div class="ag-fresh">
                    <div class="ag-fresh-title">Your new token — copy it now</div>
                    <code>{{ $freshToken }}</code>
                    <div class="ag-fresh-note">This is the only time it is shown. The server keeps a hash, like a password.</div>
                </div>
            @endif

            @forelse($tokens as $token)
                <div class="ag-row">
                    <i class="bi bi-key" style="color:#7c3aed;"></i>
                    <span class="ag-row-name">{{ $token->name }}</span>
                    <span class="ag-row-meta">
                        created {{ $token->created_at->diffForHumans() }}
                        · {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                    </span>
                    <form method="POST" action="{{ route('profile.agents.destroy', $token->id) }}"
                          onsubmit="return confirm('Revoke this token? The agent holding it loses access immediately.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="ag-btn ag-btn-danger">Revoke</button>
                    </form>
                </div>
            @empty
                <p class="ag-row-meta" style="margin:0 0 12px;">No tokens yet.</p>
            @endforelse

            <form method="POST" action="{{ route('profile.agents.store') }}" class="ag-create" style="margin-top:12px;">
                @csrf
                <input type="text" name="name" maxlength="60" required
                       placeholder="What will hold this token? e.g. a script on the server">
                <button type="submit" class="ag-btn ag-btn-primary"><i class="bi bi-plus-lg"></i> Create token</button>
            </form>
            @error('name')<div class="alert alert-danger py-2 mt-2">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="ag-section">
        <div class="ag-section-header">
            <span class="ag-section-icon" style="background:#dcfce7;color:#16a34a;"><i class="bi bi-shield-check"></i></span>
            <span class="ag-section-title">Connected apps</span>
        </div>
        <div class="ag-section-body">
            <p class="ag-lead">
                Apps that connected themselves with an <strong>Authorize</strong> click — claude.ai,
                Claude Code and the like. They hold their own keys and renew them quietly;
                revoking one here signs it out everywhere it runs.
            </p>

            @forelse($connections as $connection)
                <div class="ag-row">
                    <i class="bi bi-plug" style="color:#16a34a;"></i>
                    <span class="ag-row-name">{{ $connection->name }}</span>
                    <span class="ag-row-meta">
                        authorized {{ $connection->since->diffForHumans() }}
                        · key renewed {{ $connection->last_issued->diffForHumans() }}
                    </span>
                    <form method="POST" action="{{ route('profile.agents.connections.destroy', $connection->client_id) }}"
                          onsubmit="return confirm('Revoke this connection? The app loses access immediately.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="ag-btn ag-btn-danger">Revoke</button>
                    </form>
                </div>
            @empty
                <p class="ag-row-meta" style="margin:0;">Nothing connected yet.</p>
            @endforelse
        </div>
    </div>

    <div class="ag-section">
        <div class="ag-section-header">
            <span class="ag-section-icon" style="background:#e0edff;color:#2563eb;"><i class="bi bi-plug"></i></span>
            <span class="ag-section-title">Connecting an agent</span>
        </div>
        <div class="ag-section-body">
            <p class="ag-lead">
                The server speaks MCP (Model Context Protocol) at
                <code style="background:#f1f2f5;border-radius:4px;padding:1px 5px;">{{ $mcpUrl }}</code>.
                The agent authenticates with <code style="background:#f1f2f5;border-radius:4px;padding:1px 5px;">Authorization: Bearer &lt;token&gt;</code>.
            </p>
            <table class="ag-setup">
                <tr>
                    <th>Claude.ai</th>
                    <td>
                        Settings → Connectors → Add custom connector → URL <code>{{ $mcpUrl }}</code>.
                        Leave every other field empty and press Add — when you first use it, Claude sends
                        you here to sign in and press <strong>Authorize</strong>. No token needed.
                    </td>
                </tr>
                <tr>
                    <th>Claude Code</th>
                    <td>
                        <code>claude mcp add tasks {{ $mcpUrl }} --transport http</code>
                        — it opens the same Authorize page in your browser. Then paste a task link
                        into the chat and ask it to do the work.
                    </td>
                </tr>
                <tr>
                    <th>Scripts &amp; the rest</th>
                    <td>Anything that cannot click Authorize uses a token from the section above, sent as <code>Authorization: Bearer &lt;token&gt;</code>.</td>
                </tr>
                <tr>
                    <th>What it can do</th>
                    <td>Read tasks and projects, search the boards, read text and image attachments, post comments, move tasks between statuses, create tasks, keep checklists, and assign people (where you could). It cannot delete anything.</td>
                </tr>
            </table>
        </div>
    </div>
</div>
@endsection
