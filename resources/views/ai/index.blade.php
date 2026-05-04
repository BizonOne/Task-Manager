@extends('layouts.app')

@section('title', 'Lina AI')

@push('styles')
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>if(typeof marked!=='undefined') marked.setOptions({ breaks: true, gfm: true });</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

<style>
/* ── Override layout shell for full-height chat ── */
main {
    padding: 0 !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
}
footer { display: none !important; }
.topnav { display: none !important; }

/* ── Page layout ── */
.lina-page {
    display: flex; flex: 1; overflow: hidden; height: 100%;
}

/* ── Left: conversations panel (future multi-chat) ── */
.lina-sidebar {
    width: 240px; flex-shrink: 0;
    border-right: 1px solid var(--gray-200);
    background: var(--gray-50);
    display: flex; flex-direction: column;
    overflow: hidden;
}
.lina-sidebar-head {
    padding: 18px 16px 12px;
    border-bottom: 1px solid var(--gray-200);
}
.lina-sidebar-head h2 {
    font-size: 13px; font-weight: 700;
    color: var(--gray-700); margin: 0 0 10px;
    text-transform: uppercase; letter-spacing: .5px;
}
.lina-new-btn {
    width: 100%; padding: 8px 12px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    border: none; border-radius: 10px; color: #fff;
    font-size: 13px; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; gap: 6px;
    transition: opacity .15s;
}
.lina-new-btn:hover { opacity: .9; }
.lina-conv-list {
    flex: 1; overflow-y: auto; padding: 10px 8px;
    display: flex; flex-direction: column; gap: 2px;
}
.lina-conv-list::-webkit-scrollbar { width: 4px; }
.lina-conv-list::-webkit-scrollbar-thumb { background: var(--gray-300); border-radius: 4px; }
.lina-conv-item {
    padding: 9px 10px; border-radius: 8px;
    cursor: pointer; display: flex; align-items: center;
    gap: 8px; font-size: 12.5px; color: var(--gray-600);
    transition: background .12s; position: relative;
}
.lina-conv-item:hover { background: var(--gray-200); color: var(--gray-800); }
.lina-conv-item.active {
    background: #ede9fe; color: #5b21b6; font-weight: 600;
}
.lina-conv-item i { font-size: 13px; flex-shrink: 0; color: #7c3aed; }
.lina-conv-label {
    flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.lina-conv-del {
    opacity: 0; background: none; border: none; cursor: pointer;
    color: var(--gray-400); font-size: 12px; padding: 2px 4px; border-radius: 4px;
    transition: all .12s;
}
.lina-conv-item:hover .lina-conv-del { opacity: 1; }
.lina-conv-del:hover { background: #fef2f2; color: #ef4444; }

/* ── Right: main chat ── */
.lina-main {
    flex: 1; display: flex; flex-direction: column; overflow: hidden;
}

/* ── Chat header ── */
.lina-head {
    flex-shrink: 0; padding: 16px 24px;
    background: #fff; border-bottom: 1px solid var(--gray-200);
    display: flex; align-items: center; gap: 14px;
}
.lina-head-avatar {
    width: 44px; height: 44px; border-radius: 14px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #fff; flex-shrink: 0;
}
.lina-head-title { font-size: 17px; font-weight: 800; color: var(--gray-900); }
.lina-head-status {
    display: flex; align-items: center; gap: 5px;
    font-size: 11.5px; color: var(--gray-500); margin-top: 1px;
}
.lina-status-dot {
    width: 7px; height: 7px; border-radius: 50%; background: #10b981;
    animation: linaPulse 2s infinite;
}
@keyframes linaPulse { 0%,100%{opacity:1} 50%{opacity:.4} }
.lina-head-right { margin-left: auto; display: flex; gap: 8px; }
.lina-icon-btn {
    width: 36px; height: 36px; border-radius: 10px;
    border: 1px solid var(--gray-200); background: #fff;
    color: var(--gray-500); font-size: 15px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: all .15s;
}
.lina-icon-btn:hover { background: var(--gray-100); color: var(--gray-700); border-color: var(--gray-300); }
#linaModelPill { display: none; }

/* ── Messages ── */
.lina-messages {
    flex: 1; overflow-y: auto; padding: 24px;
    display: flex; flex-direction: column; gap: 16px;
    scroll-behavior: smooth; background: var(--gray-25);
}
.lina-messages::-webkit-scrollbar { width: 5px; }
.lina-messages::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 4px; }

/* Empty / welcome */
.lina-welcome {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; flex: 1; text-align: center;
    padding: 40px 20px; gap: 14px;
}
.lina-welcome-icon {
    width: 80px; height: 80px; border-radius: 24px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 38px; color: #fff; margin-bottom: 6px;
    box-shadow: 0 8px 24px rgba(99,102,241,.3);
}
.lina-welcome h3 { font-size: 22px; font-weight: 800; color: var(--gray-900); margin: 0; }
.lina-welcome p { font-size: 14px; color: var(--gray-500); margin: 0; max-width: 380px; line-height: 1.6; }
.lina-welcome-chips {
    display: flex; flex-wrap: wrap; gap: 8px; justify-content: center;
    margin-top: 8px;
}

/* Message bubbles */
.lina-msg-wrap { display: flex; flex-direction: column; gap: 4px; }
.lina-msg-wrap.user { align-items: flex-end; }
.lina-msg-wrap.bot  { align-items: flex-start; }

.lina-msg-meta {
    display: flex; align-items: center; gap: 8px;
    font-size: 11px; color: var(--gray-400);
}
.lina-model-tag {
    background: #ede9fe; color: #5b21b6;
    border-radius: 12px; padding: 2px 8px; font-size: 10.5px; font-weight: 600;
}

.lina-msg {
    max-width: 72%; font-size: 14px; line-height: 1.7;
    padding: 12px 16px; border-radius: 16px; word-break: break-word;
}
.lina-msg.user {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff; border-bottom-right-radius: 4px;
    box-shadow: 0 2px 8px rgba(99,102,241,.25);
}
.lina-msg.bot {
    background: #fff; color: var(--gray-800);
    border-bottom-left-radius: 4px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}
.lina-msg.bot p { margin: 0 0 8px; }
.lina-msg.bot p:last-child { margin: 0; }
.lina-msg.bot ul, .lina-msg.bot ol { margin: 4px 0 8px 20px; padding: 0; }
.lina-msg.bot li { margin-bottom: 3px; }
.lina-msg.bot code {
    background: var(--gray-100); padding: 1px 6px; border-radius: 4px;
    font-size: 12.5px; font-family: 'Courier New', monospace;
}
.lina-msg.bot pre {
    margin: 0; padding: 0; overflow-x: auto; font-size: 12.5px;
    background: #282c34; border-radius: 0;
}
.lina-msg.bot pre code { padding: 0; font-size: inherit; }
.lina-msg.bot pre code.hljs { padding: 14px 16px !important; border-radius: 0 0 10px 10px !important; font-size: 12.5px; line-height: 1.6; display: block; }

/* Code block wrapper with header */
.code-block-wrap { margin: 10px 0; border-radius: 10px; overflow: hidden; border: 1px solid #2d333b; }
.code-block-header {
    display: flex; align-items: center; justify-content: space-between;
    background: #161b22; padding: 7px 12px;
    border-bottom: 1px solid #2d333b;
}
.code-lang {
    font-size: 11px; color: #8b949e;
    font-family: 'Courier New', monospace; letter-spacing: .3px;
}
.code-copy-btn {
    background: none; border: 1px solid #30363d; border-radius: 6px;
    color: #8b949e; font-size: 11px; padding: 3px 9px; cursor: pointer;
    display: flex; align-items: center; gap: 4px; transition: all .15s;
    font-family: inherit; line-height: 1.4;
}
.code-copy-btn:hover { border-color: #6e7681; color: #e6edf3; background: rgba(255,255,255,.06); }
.lina-msg.bot strong { color: var(--gray-900); }
.lina-msg.bot h1,.lina-msg.bot h2,.lina-msg.bot h3 {
    font-size: 15px; font-weight: 700; margin: 8px 0 4px; color: var(--gray-900);
}

.lina-msg-actions { display: flex; gap: 4px; opacity: 0; transition: opacity .15s; }
.lina-msg-wrap:hover .lina-msg-actions { opacity: 1; }
.lina-copy-btn {
    background: #fff; border: 1px solid var(--gray-200); border-radius: 7px;
    padding: 3px 9px; font-size: 11.5px; color: var(--gray-500); cursor: pointer;
    display: flex; align-items: center; gap: 4px; transition: all .15s;
}
.lina-copy-btn:hover { background: var(--gray-100); color: var(--gray-700); }

/* Typing */
.lina-typing-wrap { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.lina-typing {
    background: #fff; border: 1px solid var(--gray-200); border-radius: 16px;
    border-bottom-left-radius: 4px; padding: 14px 18px;
    box-shadow: var(--shadow-sm);
}
.lina-dots span {
    display: inline-block; width: 8px; height: 8px; border-radius: 50%;
    background: var(--gray-400); margin: 0 2px;
    animation: linaBounce 1s infinite ease-in-out;
}
.lina-dots span:nth-child(2) { animation-delay: .18s; }
.lina-dots span:nth-child(3) { animation-delay: .36s; }
@keyframes linaBounce { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(-7px)} }

/* ── Input area ── */
.lina-foot {
    flex-shrink: 0;
    padding: 12px 24px 18px;
    background: #fff;
    border-top: 1px solid var(--gray-100);
}
.lina-input-box {
    background: #fff;
    border: 1.5px solid var(--gray-200);
    border-radius: 18px;
    box-shadow: 0 2px 14px rgba(0,0,0,.06);
    transition: border-color .18s, box-shadow .18s;
    overflow: hidden;
}
.lina-input-box:focus-within {
    border-color: #6366f1;
    box-shadow: 0 2px 20px rgba(99,102,241,.14);
}
#linaInput {
    display: block; width: 100%;
    border: none; outline: none; resize: none;
    font-size: 14.5px; line-height: 1.65;
    color: var(--gray-800); background: transparent;
    padding: 14px 16px 4px;
    min-height: 52px; max-height: 180px;
    overflow-y: hidden;
    font-family: inherit;
}
#linaInput::placeholder { color: var(--gray-400); }
.lina-input-toolbar {
    display: flex; align-items: center;
    justify-content: space-between;
    padding: 6px 10px 10px;
}
.lina-input-hints {
    display: flex; align-items: center; gap: 10px;
    font-size: 11px; color: var(--gray-400);
}
.lina-input-hints kbd {
    background: var(--gray-50); border: 1px solid var(--gray-200);
    border-radius: 4px; padding: 1px 6px;
    font-size: 10.5px; font-family: inherit; color: var(--gray-500);
}
.lina-input-right {
    display: flex; align-items: center; gap: 8px;
}
#linaCharCount {
    font-size: 11px; color: var(--gray-300);
}
#linaCharCount.warn { color: #f59e0b; }
#linaCharCount.over { color: #ef4444; }
.lina-send-btn {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    border: none; color: #fff; font-size: 15px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: opacity .15s, transform .12s;
}
.lina-send-btn:hover:not(:disabled) { opacity: .9; transform: scale(1.06); }
.lina-send-btn:disabled { opacity: .28; cursor: not-allowed; transform: none; }

/* Chips */
.lina-chip {
    background: #fff; border: 1px solid var(--gray-200); border-radius: 20px;
    padding: 7px 14px; font-size: 13px; color: var(--gray-600); cursor: pointer;
    transition: all .15s; white-space: nowrap;
    box-shadow: var(--shadow-sm);
}
.lina-chip:hover { background: #ede9fe; border-color: #c4b5fd; color: #5b21b6; }

/* Streaming cursor */
.lina-streaming::after {
    content: '▋'; animation: linaCursor .7s infinite;
}
@keyframes linaCursor { 0%,100%{opacity:1} 50%{opacity:0} }

/* Error */
.lina-error {
    text-align: center; font-size: 13px; color: #ef4444;
    padding: 8px 16px; background: #fef2f2;
    border-radius: 10px; border: 1px solid #fca5a5;
    margin: 0 auto; max-width: 400px;
}

/* ── Mobile sidebar slide-over ── */
@media (max-width: 768px) {
    .lina-msg { max-width: 90%; }

    /* Sidebar becomes a slide-over panel */
    .lina-sidebar {
        position: fixed; top: 0; left: 0; bottom: 0;
        z-index: 1000; width: 280px;
        transform: translateX(-100%);
        transition: transform .25s ease;
        box-shadow: 4px 0 20px rgba(0,0,0,.15);
    }
    .lina-sidebar.open { transform: translateX(0); }

    /* Backdrop behind sidebar */
    .lina-mob-backdrop {
        display: none;
        position: fixed; inset: 0; z-index: 999;
        background: rgba(0,0,0,.35);
    }
    .lina-mob-backdrop.open { display: block; }

    /* Close button inside sidebar */
    .lina-sidebar-close {
        display: flex !important;
    }

    /* Hamburger + back button in header */
    .lina-mob-menu-btn { display: flex !important; }

    /* Shrink header padding */
    .lina-head { padding: 12px 14px; gap: 10px; }
    .lina-head-avatar { width: 38px; height: 38px; font-size: 18px; border-radius: 11px; }
    .lina-head-title { font-size: 15px; }
    .lina-head-status { font-size: 10.5px; }

    /* Shrink message area padding */
    .lina-messages { padding: 14px 12px; gap: 12px; }
    .lina-foot { padding: 8px 12px 14px; }
}

/* Hidden by default on desktop */
.lina-mob-menu-btn { display: none; }
.lina-sidebar-close { display: none; }
.lina-mob-backdrop { display: none; }
</style>
@endpush

@section('content')
{{-- Mobile backdrop --}}
<div class="lina-mob-backdrop" id="linaMobBackdrop" onclick="closeMobSidebar()"></div>

<div class="lina-page">

    {{-- Left conversations sidebar --}}
    <div class="lina-sidebar" id="linaSidebar">
        <div class="lina-sidebar-head">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <h2 style="margin:0;">Conversations</h2>
                <button class="lina-sidebar-close" onclick="closeMobSidebar()" title="Close" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--gray-500);padding:2px 6px;border-radius:6px;">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <button class="lina-new-btn" onclick="newConversation()">
                <i class="bi bi-plus-lg"></i> New Chat
            </button>
        </div>
        <div class="lina-conv-list" id="linaConvList"></div>
    </div>

    {{-- Main chat area --}}
    <div class="lina-main">

        {{-- Header --}}
        <div class="lina-head">
            {{-- Mobile: hamburger to open sidebar --}}
            <button class="lina-mob-menu-btn lina-icon-btn" onclick="openMobSidebar()" title="Conversations" style="flex-shrink:0;">
                <i class="bi bi-layout-sidebar"></i>
            </button>
            <div class="lina-head-avatar"><i class="bi bi-stars"></i></div>
            <div style="flex:1;min-width:0;">
                <div class="lina-head-title">Lina</div>
                <div class="lina-head-status">
                    <span class="lina-status-dot"></span>
                    <span>Online &mdash; knows your workspace data</span>
                </div>
            </div>
            <div class="lina-head-right">
                {{-- Mobile: back to app button --}}
                <a href="{{ url()->previous() == url()->current() ? route('dashboard') : url()->previous() }}" class="lina-icon-btn" title="Back" style="text-decoration:none;">
                    <i class="bi bi-arrow-left"></i>
                </a>
            </div>
        </div>

        {{-- Messages --}}
        <div class="lina-messages" id="linaMessages">
            <div class="lina-welcome" id="linaWelcome">
                <div class="lina-welcome-icon"><i class="bi bi-stars"></i></div>
                <h3>Hi, I'm Lina!</h3>
                <p>I'm your AI assistant. I have full access to your tasks, projects, notes, reminders, and routines. Ask me anything.</p>
                <div class="lina-welcome-chips">
                    <span class="lina-chip" onclick="askChip(this)">What tasks are due today?</span>
                    <span class="lina-chip" onclick="askChip(this)">Show high priority tasks</span>
                    <span class="lina-chip" onclick="askChip(this)">Summarize my projects</span>
                    <span class="lina-chip" onclick="askChip(this)">Any overdue reminders?</span>
                    <span class="lina-chip" onclick="askChip(this)">What routines do I have?</span>
                    <span class="lina-chip" onclick="askChip(this)">Show my recent notes</span>
                </div>
            </div>
        </div>

        {{-- Input --}}
        <div class="lina-foot">
            <div class="lina-input-box">
                <textarea id="linaInput"
                    placeholder="Message Lina…"
                    rows="1" maxlength="2000"></textarea>
                <div class="lina-input-toolbar">
                    <div class="lina-input-hints">
                        <span><kbd>Enter</kbd> send</span>
                        <span><kbd>Shift+Enter</kbd> new line</span>
                    </div>
                    <div class="lina-input-right">
                        <span id="linaCharCount"></span>
                        <button class="lina-send-btn" id="linaSend" onclick="window.sendMessage()" title="Send">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    /* ── Config ── */
    const STREAM_URL    = "{{ route('ai.stream') }}";
    const CONV_URL      = "{{ url('/ai/conversations') }}";
    const CSRF          = "{{ csrf_token() }}";
    const MAX_HISTORY   = 20;
    const MAX_CHARS     = 2000;

    /* ── State ── */
    let conversations = [];   // [{ id, label, updated_at }]
    let activeConvId  = null;
    let activeMessages= [];   // [{ role, content, model, created_at }]
    let isBusy        = false;

    /* ── DOM ── */
    const msgsEl    = document.getElementById('linaMessages');
    const welcome   = document.getElementById('linaWelcome');
    const input     = document.getElementById('linaInput');
    const sendBtn   = document.getElementById('linaSend');
    const charCount = document.getElementById('linaCharCount');
    const convList  = document.getElementById('linaConvList');

    /* ── API helpers ── */
    async function api(method, url, body) {
        const opts = {
            method,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        };
        if (body) opts.body = JSON.stringify(body);
        const res = await fetch(url, opts);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    /* ── Conversations management ── */
    async function loadConversations() {
        try {
            conversations = await api('GET', CONV_URL);
        } catch { conversations = []; }
        renderConvList();
        if (conversations.length) {
            await switchConversation(conversations[0].id);
        } else {
            await newConversation();
        }
    }

    function renderConvList() {
        convList.innerHTML = '';
        if (!conversations.length) {
            convList.innerHTML = '<div style="font-size:12px;color:var(--gray-400);padding:10px;text-align:center;">No conversations yet</div>';
            return;
        }
        conversations.forEach(conv => {
            const item = document.createElement('div');
            item.className = 'lina-conv-item' + (conv.id === activeConvId ? ' active' : '');
            item.dataset.id = conv.id;
            item.innerHTML = `
                <i class="bi bi-chat-left-text"></i>
                <span class="lina-conv-label">${escHtml(conv.label || 'New Chat')}</span>
                <button class="lina-conv-del" onclick="deleteConv(${conv.id}, event)" title="Delete"><i class="bi bi-x"></i></button>
            `;
            item.addEventListener('click', () => switchConversation(conv.id));
            convList.appendChild(item);
        });
    }

    window.newConversation = async function () {
        // If the active conversation is already empty, just focus it — don't create another
        if (activeConvId && activeMessages.length === 0) {
            if (window.closeMobSidebar) closeMobSidebar();
            return;
        }
        try {
            const conv = await api('POST', CONV_URL, { label: 'New Chat' });
            conversations.unshift(conv);
            renderConvList();
            await switchConversation(conv.id);
        } catch (e) { console.error('[Lina] newConversation', e); }
    };

    window.switchConversation = async function (id) {
        activeConvId = id;
        activeMessages = [];
        renderConvList();
        msgsEl.innerHTML = '';
        msgsEl.appendChild(welcome);
        // Close sidebar on mobile after picking a conversation
        if (window.closeMobSidebar) closeMobSidebar();
        try {
            const data = await api('GET', CONV_URL + '/' + id);
            activeMessages = data.messages || [];
            renderMessages();
        } catch (e) { console.error('[Lina] switchConversation', e); }
    };

    window.deleteConv = async function (id, e) {
        e.stopPropagation();
        if (!confirm('Delete this conversation?')) return;
        try {
            await api('DELETE', CONV_URL + '/' + id);
            conversations = conversations.filter(c => c.id !== id);
            if (conversations.length) {
                await switchConversation(conversations[0].id);
            } else {
                await newConversation();
            }
            renderConvList();
        } catch (e) { console.error('[Lina] deleteConv', e); }
    };

    /* ── Messages rendering ── */
    function renderMessages() {
        msgsEl.innerHTML = '';
        if (!activeMessages.length) {
            msgsEl.appendChild(welcome);
            return;
        }
        activeMessages.forEach(m => renderBubble(m.role, m.content, m.created_at, false, m.model));
        scrollBottom();
    }

    function renderBubble(role, content, time, animate, model) {
        const wrap = document.createElement('div');
        wrap.className = 'lina-msg-wrap ' + (role === 'user' ? 'user' : 'bot');
        if (animate) { wrap.style.opacity = '0'; wrap.style.transform = 'translateY(8px)'; wrap.style.transition = 'all .2s'; }

        // Meta
        const meta = document.createElement('div');
        meta.className = 'lina-msg-meta';
        if (role !== 'user' && model) {
            const tag = document.createElement('span');
            tag.className = 'lina-model-tag';
            tag.textContent = friendlyModel(model);
            meta.appendChild(tag);
        }
        const ts = document.createElement('span');
        ts.textContent = formatTime(time);
        meta.appendChild(ts);
        wrap.appendChild(meta);

        // Bubble
        const bubble = document.createElement('div');
        bubble.className = 'lina-msg ' + (role === 'user' ? 'user' : 'bot');
        if (role !== 'user' && typeof marked !== 'undefined') {
            bubble.innerHTML = marked.parse(content);
            applyCodeEnhancements(bubble);
        } else {
            bubble.textContent = content;
        }
        wrap.appendChild(bubble);

        // Copy action
        const actions = document.createElement('div');
        actions.className = 'lina-msg-actions';
        const copyBtn = document.createElement('button');
        copyBtn.className = 'lina-copy-btn';
        copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
        copyBtn.onclick = () => copyText(content, copyBtn);
        actions.appendChild(copyBtn);
        wrap.appendChild(actions);

        msgsEl.appendChild(wrap);
        if (animate) requestAnimationFrame(() => { wrap.style.opacity = '1'; wrap.style.transform = 'translateY(0)'; });
        return wrap;
    }

    function appendTyping() {
        const wrap = document.createElement('div');
        wrap.className = 'lina-typing-wrap';
        const meta = document.createElement('div');
        meta.className = 'lina-msg-meta'; meta.textContent = 'Lina is thinking…';
        wrap.appendChild(meta);
        const t = document.createElement('div');
        t.className = 'lina-typing';
        t.innerHTML = '<div class="lina-dots"><span></span><span></span><span></span></div>';
        wrap.appendChild(t);
        msgsEl.appendChild(wrap);
        scrollBottom();
        return wrap;
    }

    function appendError(msg) {
        const div = document.createElement('div');
        div.className = 'lina-error';
        div.textContent = '⚠ ' + msg;
        msgsEl.appendChild(div);
        scrollBottom();
    }

    /* ── Send ── */
    window.sendMessage = async function () {
        const text = input.value.trim();
        if (!text || isBusy || text.length > MAX_CHARS) return;
        if (!activeConvId) return;

        // Remove welcome if present
        if (welcome.parentNode) welcome.parentNode.removeChild(welcome);

        const timestamp = new Date().toISOString();
        activeMessages.push({ role: 'user', content: text, created_at: timestamp });

        // Optimistically update label if first message
        if (activeMessages.length === 1) {
            const conv = conversations.find(c => c.id === activeConvId);
            if (conv) { conv.label = text.slice(0, 60); renderConvList(); }
        }

        renderBubble('user', text, timestamp, true);
        scrollBottom();

        input.value = ''; autoResize(); updateCharCount();

        const typingEl = appendTyping();
        isBusy = true; sendBtn.disabled = true;

        const historyPayload = activeMessages.slice(0, -1).slice(-MAX_HISTORY).map(m => ({
            role: m.role === 'assistant' ? 'assistant' : 'user',
            content: m.content,
        }));

        let accumulatedText = '';
        let selectedModel   = null;
        let streamWrap      = null;
        let streamBubbleEl  = null;
        let streamMetaEl    = null;

        try {
            const res = await fetch(STREAM_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ message: text, conversation_id: activeConvId, history: historyPayload }),
            });

            typingEl.remove();

            if (!res.ok) {
                appendError('Server error ' + res.status + '. Please try again.');
                activeMessages.pop();
                return;
            }

            // Create streaming bubble
            const streamTs = new Date().toISOString();
            streamWrap = document.createElement('div');
            streamWrap.className = 'lina-msg-wrap bot';
            streamWrap.style.cssText = 'opacity:0;transform:translateY(8px);transition:all .2s';

            streamMetaEl = document.createElement('div');
            streamMetaEl.className = 'lina-msg-meta';
            const tsMeta = document.createElement('span');
            tsMeta.textContent = formatTime(streamTs);
            streamMetaEl.appendChild(tsMeta);
            streamWrap.appendChild(streamMetaEl);

            streamBubbleEl = document.createElement('div');
            streamBubbleEl.className = 'lina-msg bot lina-streaming';
            streamWrap.appendChild(streamBubbleEl);
            msgsEl.appendChild(streamWrap);
            requestAnimationFrame(() => { streamWrap.style.opacity='1'; streamWrap.style.transform='translateY(0)'; });
            scrollBottom();

            // Read SSE stream
            const reader  = res.body.getReader();
            const decoder = new TextDecoder();
            let sseBuffer  = '';

            outer: while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                sseBuffer += decoder.decode(value, { stream: true });

                const lines = sseBuffer.split('\n');
                sseBuffer   = lines.pop();

                for (const line of lines) {
                    const trimmed = line.trim();
                    if (!trimmed.startsWith('data: ')) continue;
                    const data = trimmed.slice(6);
                    if (data === '[DONE]') break outer;

                    try {
                        const json = JSON.parse(data);
                        if (json.conversation_id !== undefined && json.choices === undefined) {
                            // Our metadata packet: { model, conversation_id }
                            if (json.model) {
                                selectedModel = json.model;
                                const tag = document.createElement('span');
                                tag.className = 'lina-model-tag';
                                tag.textContent = friendlyModel(selectedModel);
                                streamMetaEl.prepend(tag);
                            }
                        } else if (json.error) {
                            streamBubbleEl.classList.remove('lina-streaming');
                            const errMsg = typeof json.error === 'string' ? json.error : (json.error?.message || 'Something went wrong. Please try again.');
                            streamBubbleEl.textContent = '⚠ ' + errMsg;
                        } else {
                            const token = json.choices?.[0]?.delta?.content || '';
                            if (token) {
                                accumulatedText += token;
                                streamBubbleEl.textContent = accumulatedText;
                                scrollBottom();
                            }
                        }
                    } catch (e) { /* ignore parse errors */ }
                }
            }

            // Final markdown render
            streamBubbleEl.classList.remove('lina-streaming');
            if (accumulatedText) {
                streamBubbleEl.innerHTML = typeof marked !== 'undefined'
                    ? marked.parse(accumulatedText)
                    : accumulatedText.replace(/\n/g, '<br>');
                applyCodeEnhancements(streamBubbleEl);
                const actions = document.createElement('div');
                actions.className = 'lina-msg-actions';
                const copyBtn = document.createElement('button');
                copyBtn.className = 'lina-copy-btn';
                copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                const capturedText = accumulatedText;
                copyBtn.onclick = () => copyText(capturedText, copyBtn);
                actions.appendChild(copyBtn);
                streamWrap.appendChild(actions);

                const finalTs = new Date().toISOString();
                activeMessages.push({ role: 'assistant', content: accumulatedText, model: selectedModel, created_at: finalTs });
                // Refresh conv list so updated_at order updates
                api('GET', CONV_URL).then(c => { conversations = c; renderConvList(); }).catch(() => {});
            } else if (!streamBubbleEl.textContent.includes('⚠')) {
                streamBubbleEl.textContent = 'No response received.';
                activeMessages.pop();
            }

            scrollBottom();

        } catch (e) {
            if (typingEl.parentNode) typingEl.remove();
            if (streamWrap && streamWrap.parentNode) streamWrap.remove();
            appendError('Network error. Check your connection.');
            activeMessages.pop();
            console.error('[Lina]', e);
        } finally {
            isBusy = false; sendBtn.disabled = false; input.focus();
        }
    };

    window.askChip = function (el) {
        input.value = el.textContent.trim();
        autoResize(); updateCharCount(); sendMessage();
    };

    /* ── Mobile sidebar ── */
    const mobSidebar  = document.getElementById('linaSidebar');
    const mobBackdrop = document.getElementById('linaMobBackdrop');
    window.openMobSidebar  = function () { mobSidebar.classList.add('open'); mobBackdrop.classList.add('open'); document.body.style.overflow='hidden'; };
    window.closeMobSidebar = function () { mobSidebar.classList.remove('open'); mobBackdrop.classList.remove('open'); document.body.style.overflow=''; };

    /* ── Toolbar actions ── */
    window.clearConversation = async function () {
        if (!activeConvId || !activeMessages.length) return;
        if (!confirm('Clear this conversation?')) return;
        try {
            await api('POST', CONV_URL + '/' + activeConvId + '/clear');
            activeMessages = [];
            const conv = conversations.find(c => c.id === activeConvId);
            if (conv) conv.label = 'New Chat';
            renderConvList(); renderMessages();
        } catch (e) { console.error('[Lina] clear', e); }
    };

    window.exportChat = function () {
        if (!activeMessages.length) return;
        const lines = activeMessages.map(m =>
            '[' + formatTime(m.created_at) + '] ' +
            (m.role === 'user' ? 'You' : 'Lina' + (m.model ? ' (' + m.model + ')' : '')) +
            ':\n' + m.content + '\n'
        );
        const blob = new Blob([lines.join('\n')], { type: 'text/plain' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'lina-chat-' + new Date().toISOString().slice(0,10) + '.txt';
        a.click();
    };

    /* ── Helpers ── */
    function scrollBottom() { setTimeout(() => msgsEl.scrollTop = msgsEl.scrollHeight, 30); }

    function friendlyModel(model) {
        return 'Lina';
    }

    function formatTime(iso) {
        if (!iso) return '';
        try { return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
        catch { return ''; }
    }

    function fallbackCopy(text, btn, ok, def) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
        document.body.appendChild(ta);
        ta.focus(); ta.select();
        try { document.execCommand('copy'); btn.innerHTML = ok; setTimeout(() => btn.innerHTML = def, 1800); } catch {}
        document.body.removeChild(ta);
    }

    function copyText(text, btn) {
        const ok  = '<i class="bi bi-check2"></i> Copied!';
        const def = '<i class="bi bi-clipboard"></i> Copy';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text)
                .then(() => { btn.innerHTML = ok; setTimeout(() => btn.innerHTML = def, 1800); })
                .catch(() => fallbackCopy(text, btn, ok, def));
        } else {
            fallbackCopy(text, btn, ok, def);
        }
    }

    function applyCodeEnhancements(el) {
        el.querySelectorAll('pre code').forEach(codeEl => {
            if (typeof hljs !== 'undefined' && !codeEl.dataset.highlighted) {
                hljs.highlightElement(codeEl);
            }
            const pre = codeEl.parentNode;
            if (pre.dataset.enhanced) return;
            pre.dataset.enhanced = '1';

            const lang = [...codeEl.classList]
                .find(c => c.startsWith('language-'))?.replace('language-', '') || 'plaintext';

            const wrapper = document.createElement('div');
            wrapper.className = 'code-block-wrap';
            pre.parentNode.insertBefore(wrapper, pre);
            wrapper.appendChild(pre);

            const header = document.createElement('div');
            header.className = 'code-block-header';
            const langSpan = document.createElement('span');
            langSpan.className = 'code-lang';
            langSpan.textContent = lang;
            const copyBtn = document.createElement('button');
            copyBtn.className = 'code-copy-btn';
            copyBtn.type = 'button';
            copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy code';
            copyBtn.addEventListener('click', function () {
                const ok  = '<i class="bi bi-check2"></i> Copied!';
                const def = '<i class="bi bi-clipboard"></i> Copy code';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(codeEl.innerText)
                        .then(() => { this.innerHTML = ok; setTimeout(() => { this.innerHTML = def; }, 1800); })
                        .catch(() => fallbackCopy(codeEl.innerText, this, ok, def));
                } else {
                    fallbackCopy(codeEl.innerText, this, ok, def);
                }
            });
            header.appendChild(langSpan);
            header.appendChild(copyBtn);
            wrapper.insertBefore(header, pre);
        });
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function autoResize() {
        input.style.height = 'auto';
        const h = Math.min(input.scrollHeight, 180);
        input.style.height = h + 'px';
        input.style.overflowY = input.scrollHeight > 180 ? 'auto' : 'hidden';
    }

    function updateCharCount() {
        const len = input.value.length;
        if (len === 0) { charCount.textContent = ''; charCount.className = ''; return; }
        charCount.textContent = len + ' / ' + MAX_CHARS;
        charCount.className = len > MAX_CHARS ? 'over' : len > MAX_CHARS * 0.85 ? 'warn' : '';
    }

    input.addEventListener('input', () => { autoResize(); updateCharCount(); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); window.sendMessage(); }
    });

    /* ── Boot ── */
    loadConversations();
    autoResize();
})();
</script>
@endpush
