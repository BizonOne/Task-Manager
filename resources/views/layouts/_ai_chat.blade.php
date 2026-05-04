{{-- ═══════════════════════════════════════════════════
     AI Chat Drawer — Full sidebar chat with multi-turn,
     markdown, model badges, timestamps, copy, logging
     ═══════════════════════════════════════════════════ --}}

{{-- marked.js for markdown rendering --}}
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script>if(typeof marked!=='undefined') marked.setOptions({ breaks: true, gfm: true });</script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>

<style>
/* ── Backdrop ── */
#aiBackdrop {
    position: fixed; inset: 0; z-index: 1049;
    background: rgba(15,23,42,.35); backdrop-filter: blur(2px);
    opacity: 0; pointer-events: none;
    transition: opacity .25s;
}
#aiBackdrop.open { opacity: 1; pointer-events: all; }

/* ── Drawer ── */
#aiDrawer {
    position: fixed; top: 0; right: 0; bottom: 0; z-index: 1050;
    width: 440px; max-width: 100vw;
    background: #fff;
    box-shadow: -8px 0 32px rgba(0,0,0,.14);
    display: flex; flex-direction: column;
    transform: translateX(100%);
    transition: transform .28s cubic-bezier(.4,0,.2,1);
}
#aiDrawer.open { transform: translateX(0); }

/* ── Header ── */
.ai-drawer-head {
    flex-shrink: 0;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    padding: 16px 18px;
    display: flex; align-items: center; gap: 12px;
}
.ai-drawer-avatar {
    width: 40px; height: 40px; border-radius: 12px;
    background: rgba(255,255,255,.18);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #fff; flex-shrink: 0;
}
.ai-drawer-title { color: #fff; font-weight: 700; font-size: 15px; line-height: 1.2; }
.ai-drawer-sub   { color: rgba(255,255,255,.7); font-size: 11px; margin-top: 1px; }
.ai-head-actions { margin-left: auto; display: flex; gap: 6px; align-items: center; }
.ai-head-btn {
    width: 32px; height: 32px; border-radius: 8px;
    border: none; background: rgba(255,255,255,.18);
    color: #fff; cursor: pointer; font-size: 15px;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.ai-head-btn:hover { background: rgba(255,255,255,.32); }
#aiModelPill {
    background: rgba(255,255,255,.18); border-radius: 20px;
    padding: 3px 10px; font-size: 10px; color: rgba(255,255,255,.9);
    font-weight: 500; letter-spacing: .3px;
}

/* ── Info bar ── */
.ai-info-bar {
    flex-shrink: 0; background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 6px 18px; display: flex; align-items: center; gap: 8px;
}
.ai-info-bar span { font-size: 11px; color: #64748b; }
.ai-info-bar strong { color: #334155; }

/* ── Messages ── */
#aiMessages {
    flex: 1; overflow-y: auto;
    padding: 16px 18px; display: flex; flex-direction: column; gap: 14px;
    scroll-behavior: smooth;
}
#aiMessages::-webkit-scrollbar { width: 5px; }
#aiMessages::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }

.ai-msg-wrap { display: flex; flex-direction: column; gap: 3px; }
.ai-msg-wrap.user { align-items: flex-end; }
.ai-msg-wrap.bot  { align-items: flex-start; }

.ai-msg-meta {
    display: flex; align-items: center; gap: 6px;
    font-size: 10.5px; color: #94a3b8; margin-bottom: 2px;
}
.ai-msg-meta .ai-model-tag {
    background: #ede9fe; color: #6d28d9;
    border-radius: 10px; padding: 1px 7px; font-size: 10px; font-weight: 600;
}

.ai-msg {
    max-width: 88%; font-size: 13.5px; line-height: 1.65;
    padding: 10px 14px; border-radius: 14px; word-break: break-word;
    position: relative;
}
.ai-msg.user {
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    color: #fff; border-bottom-right-radius: 3px;
}
.ai-msg.bot {
    background: #f1f5f9; color: #1e293b;
    border-bottom-left-radius: 3px; border: 1px solid #e2e8f0;
}
.ai-msg.bot p { margin: 0 0 6px; }
.ai-msg.bot p:last-child { margin: 0; }
.ai-msg.bot ul, .ai-msg.bot ol { margin: 4px 0 6px 18px; padding: 0; }
.ai-msg.bot li { margin-bottom: 3px; }
.ai-msg.bot code {
    background: #e2e8f0; padding: 1px 5px; border-radius: 4px;
    font-size: 12px; font-family: 'Courier New', monospace;
}
.ai-msg.bot pre {
    margin: 0; padding: 0; overflow-x: auto; font-size: 12px;
    background: #282c34; border-radius: 0;
}
.ai-msg.bot pre code { padding: 0; font-size: inherit; }
.ai-msg.bot pre code.hljs { padding: 12px 14px !important; border-radius: 0 0 8px 8px !important; font-size: 12px; line-height: 1.6; display: block; }

/* Code block wrapper */
.ai-code-wrap { margin: 8px 0; border-radius: 8px; overflow: hidden; border: 1px solid #2d333b; }
.ai-code-header {
    display: flex; align-items: center; justify-content: space-between;
    background: #161b22; padding: 6px 10px;
    border-bottom: 1px solid #2d333b;
}
.ai-code-lang { font-size: 10.5px; color: #8b949e; font-family: 'Courier New', monospace; letter-spacing: .3px; }
.ai-code-copy {
    background: none; border: 1px solid #30363d; border-radius: 5px;
    color: #8b949e; font-size: 10.5px; padding: 2px 8px; cursor: pointer;
    display: flex; align-items: center; gap: 3px; transition: all .15s;
    font-family: inherit; line-height: 1.4;
}
.ai-code-copy:hover { border-color: #6e7681; color: #e6edf3; background: rgba(255,255,255,.06); }
.ai-msg.bot strong { color: #0f172a; }
.ai-msg.bot h1,.ai-msg.bot h2,.ai-msg.bot h3 { font-size: 14px; font-weight: 700; margin: 6px 0 3px; }

.ai-msg-actions { display: flex; gap: 4px; margin-top: 3px; opacity: 0; transition: opacity .15s; }
.ai-msg-wrap:hover .ai-msg-actions { opacity: 1; }
.ai-copy-btn {
    background: none; border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 2px 8px; font-size: 11px; color: #64748b; cursor: pointer;
    display: flex; align-items: center; gap: 3px; transition: all .15s;
}
.ai-copy-btn:hover { background: #f1f5f9; color: #334155; }

/* Typing */
.ai-typing-wrap { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; }
.ai-typing {
    background: #f1f5f9; border: 1px solid #e2e8f0;
    border-radius: 14px; border-bottom-left-radius: 3px;
    padding: 12px 16px;
}
.ai-dots span {
    display: inline-block; width: 7px; height: 7px; border-radius: 50%;
    background: #94a3b8; margin: 0 2px;
    animation: aiBounce .9s infinite ease-in-out;
}
.ai-dots span:nth-child(2) { animation-delay: .15s; }
.ai-dots span:nth-child(3) { animation-delay: .3s; }
@keyframes aiBounce { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(-6px)} }

/* Suggestions */
#aiSuggestions {
    flex-shrink: 0; padding: 0 18px 12px;
    display: flex; flex-wrap: wrap; gap: 6px;
}
.ai-chip {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 20px;
    padding: 5px 12px; font-size: 12px; color: #475569; cursor: pointer;
    transition: all .15s; white-space: nowrap;
}
.ai-chip:hover { background: #ede9fe; border-color: #c4b5fd; color: #6d28d9; }

/* Footer */
.ai-drawer-foot {
    flex-shrink: 0; border-top: 1px solid #e2e8f0;
    padding: 12px 18px; background: #fff;
}
.ai-input-row { display: flex; gap: 8px; align-items: flex-end; }
#aiInput {
    flex: 1; border: 1.5px solid #e2e8f0; border-radius: 12px;
    padding: 10px 14px; font-size: 13.5px; outline: none;
    resize: none; color: #1e293b; line-height: 1.5;
    max-height: 120px; font-family: inherit;
    transition: border-color .15s; background: #f8fafc;
}
#aiInput:focus { border-color: #6366f1; background: #fff; }
#aiSend {
    width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    border: none; color: #fff; font-size: 16px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: opacity .15s, transform .1s;
}
#aiSend:hover:not(:disabled) { transform: scale(1.05); }
#aiSend:disabled { opacity: .5; cursor: not-allowed; }
.ai-foot-meta {
    display: flex; justify-content: space-between;
    margin-top: 6px; font-size: 11px; color: #94a3b8;
}
#aiCharCount.warn { color: #f59e0b; }
#aiCharCount.over { color: #ef4444; }

/* Streaming cursor */
.ai-streaming::after {
    content: '▋'; animation: aiCursor .7s infinite;
}
@keyframes aiCursor { 0%,100%{opacity:1} 50%{opacity:0} }

/* Empty state */
.ai-empty {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; height: 100%; gap: 10px; text-align: center; padding: 30px;
}
.ai-empty-icon {
    width: 64px; height: 64px; border-radius: 20px;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; color: #fff; margin-bottom: 6px;
}
.ai-empty h5 { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; }
.ai-empty p  { font-size: 12.5px; color: #64748b; margin: 4px 0 0; line-height: 1.5; }

@media (max-width: 480px) { #aiDrawer { width: 100vw; } }
</style>

{{-- Backdrop --}}
<div id="aiBackdrop" onclick="closeAiDrawer()"></div>

{{-- Drawer --}}
<div id="aiDrawer" role="dialog" aria-label="AI Chat Assistant">
    <div class="ai-drawer-head">
        <div class="ai-drawer-avatar"><i class="bi bi-stars"></i></div>
        <div>
            <div class="ai-drawer-title">AI Assistant</div>
            <div class="ai-drawer-sub">Context-aware &middot; Multi-turn</div>
        </div>
        <div class="ai-head-actions">
            <span id="aiModelPill">—</span>
            <button class="ai-head-btn" onclick="clearAiChat()" title="Clear conversation"><i class="bi bi-trash3"></i></button>
            <button class="ai-head-btn" onclick="exportAiChat()" title="Export chat"><i class="bi bi-download"></i></button>
            <button class="ai-head-btn" onclick="closeAiDrawer()" title="Close"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>

    <div class="ai-info-bar">
        <i class="bi bi-info-circle" style="font-size:11px;"></i>
        <span>Conversation stored locally &middot; <span id="aiMsgCount">0 messages</span></span>
    </div>

    <div id="aiMessages">
        <div class="ai-empty" id="aiEmptyState">
            <div class="ai-empty-icon"><i class="bi bi-stars"></i></div>
            <h5>Your AI Assistant</h5>
            <p>Ask me anything about your tasks, projects,<br>notes, reminders, or routines.</p>
        </div>
    </div>

    <div id="aiSuggestions">
        <span class="ai-chip" onclick="askChip(this)">What tasks are due today?</span>
        <span class="ai-chip" onclick="askChip(this)">High priority tasks</span>
        <span class="ai-chip" onclick="askChip(this)">Summarize my projects</span>
        <span class="ai-chip" onclick="askChip(this)">Overdue reminders?</span>
        <span class="ai-chip" onclick="askChip(this)">My routines</span>
        <span class="ai-chip" onclick="askChip(this)">Recent notes</span>
    </div>

    <div class="ai-drawer-foot">
        <div class="ai-input-row">
            <textarea id="aiInput" placeholder="Ask anything… (Shift+Enter for new line)" rows="1" maxlength="2000"></textarea>
            <button id="aiSend" onclick="sendAiMessage()" title="Send"><i class="bi bi-send-fill"></i></button>
        </div>
        <div class="ai-foot-meta">
            <span><kbd style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:1px 5px;font-size:10px;">Enter</kbd> send &nbsp; <kbd style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:1px 5px;font-size:10px;">Shift+Enter</kbd> new line</span>
            <span id="aiCharCount">0 / 2000</span>
        </div>
    </div>
</div>

<script>
(function () {
    const STORAGE_KEY = 'ai_chat_history_v2';
    const MAX_HISTORY = 20;
    const MAX_CHARS   = 2000;
    let isBusy  = false;
    let history = [];

    const drawer    = document.getElementById('aiDrawer');
    const backdrop  = document.getElementById('aiBackdrop');
    const messages  = document.getElementById('aiMessages');
    const input     = document.getElementById('aiInput');
    const sendBtn   = document.getElementById('aiSend');
    const sugg      = document.getElementById('aiSuggestions');
    const modelPill = document.getElementById('aiModelPill');
    const charCount = document.getElementById('aiCharCount');
    const msgCount  = document.getElementById('aiMsgCount');
    const emptyState= document.getElementById('aiEmptyState');

    /* Open / close */
    window.openAiDrawer = function () {
        drawer.classList.add('open');
        backdrop.classList.add('open');
        document.body.style.overflow = 'hidden';
        loadHistory();
        setTimeout(() => input.focus(), 280);
    };
    window.closeAiDrawer = function () {
        drawer.classList.remove('open');
        backdrop.classList.remove('open');
        document.body.style.overflow = '';
    };
    window.toggleAiDrawer = function () {
        drawer.classList.contains('open') ? closeAiDrawer() : openAiDrawer();
    };
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && drawer.classList.contains('open')) closeAiDrawer();
    });

    /* Load persisted history */
    function loadHistory() {
        try { history = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); }
        catch { history = []; }
        messages.innerHTML = '';
        if (history.length === 0) {
            messages.appendChild(emptyState);
            sugg.style.display = '';
        } else {
            history.forEach(h => renderMessage(h.role, h.content, h.model, h.time, false));
            sugg.style.display = 'none';
            const lastBot = [...history].reverse().find(h => h.role === 'assistant');
            if (lastBot) updateModelPill(lastBot.model);
        }
        updateMsgCount();
        scrollBottom();
    }

    function saveHistory() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(history.slice(-60)));
    }

    /* Send */
    window.sendAiMessage = async function () {
        const text = input.value.trim();
        if (!text || isBusy || text.length > MAX_CHARS) return;

        if (emptyState.parentNode) emptyState.parentNode.removeChild(emptyState);
        sugg.style.display = 'none';

        const timestamp = new Date().toISOString();
        history.push({ role: 'user', content: text, time: timestamp });
        saveHistory();
        renderMessage('user', text, null, timestamp, true);
        updateMsgCount();

        input.value = ''; autoResize(); updateCharCount();

        const typingEl = appendTyping();
        isBusy = true; sendBtn.disabled = true;

        const historyPayload = history.slice(0, -1).slice(-MAX_HISTORY)
            .map(h => ({ role: h.role === 'assistant' ? 'assistant' : 'user', content: h.content }));

        let accumulatedText = '';
        let selectedModel   = null;
        let streamWrap      = null;
        let streamBubbleEl  = null;

        try {
            const res = await fetch('{{ route("ai.stream") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ message: text, history: historyPayload }),
            });

            typingEl.remove();

            if (!res.ok) {
                appendError('Server error ' + res.status + '. Please try again.');
                history.pop(); saveHistory();
                return;
            }

            // Create streaming bubble
            const streamTs = new Date().toISOString();
            streamWrap = document.createElement('div');
            streamWrap.className = 'ai-msg-wrap bot';
            streamWrap.style.cssText = 'opacity:0;transform:translateY(8px);transition:all .2s';

            const metaEl = document.createElement('div');
            metaEl.className = 'ai-msg-meta';
            const tsMeta = document.createElement('span');
            tsMeta.textContent = formatTime(streamTs);
            metaEl.appendChild(tsMeta);
            streamWrap.appendChild(metaEl);

            streamBubbleEl = document.createElement('div');
            streamBubbleEl.className = 'ai-msg bot ai-streaming';
            streamWrap.appendChild(streamBubbleEl);
            messages.appendChild(streamWrap);
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
                        if (json.model && json.choices === undefined) {
                            // Our metadata packet: { model } (no choices)
                            selectedModel = json.model;
                            const tag = document.createElement('span');
                            tag.className = 'ai-model-tag';
                            tag.textContent = friendlyModel(selectedModel);
                            metaEl.prepend(tag);
                            updateModelPill(selectedModel);
                        } else if (json.error) {
                            streamBubbleEl.classList.remove('ai-streaming');
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
            streamBubbleEl.classList.remove('ai-streaming');
            if (accumulatedText) {
                streamBubbleEl.innerHTML = typeof marked !== 'undefined'
                    ? marked.parse(accumulatedText)
                    : accumulatedText.replace(/\n/g, '<br>');
                applyCodeEnhancements(streamBubbleEl);
                const actions = document.createElement('div');
                actions.className = 'ai-msg-actions';
                const copyBtn = document.createElement('button');
                copyBtn.className = 'ai-copy-btn';
                copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
                const capturedText = accumulatedText;
                copyBtn.onclick = () => copyText(capturedText, copyBtn);
                actions.appendChild(copyBtn);
                streamWrap.appendChild(actions);

                const finalTs = new Date().toISOString();
                history.push({ role: 'assistant', content: accumulatedText, model: selectedModel, time: finalTs });
                saveHistory();
                updateMsgCount();
            } else if (!streamBubbleEl.textContent.includes('⚠')) {
                streamBubbleEl.textContent = 'No response received.';
                history.pop(); saveHistory();
            }

            scrollBottom();

        } catch (e) {
            if (typingEl.parentNode) typingEl.remove();
            if (streamWrap && streamWrap.parentNode) streamWrap.remove();
            appendError('Network error. Check your connection.');
            history.pop(); saveHistory();
            console.error('[AI]', e);
        } finally {
            isBusy = false; sendBtn.disabled = false; input.focus();
        }
    };

    window.askChip = function (el) {
        input.value = el.textContent.trim();
        autoResize(); updateCharCount(); sendAiMessage();
    };

    /* Render bubble */
    function renderMessage(role, content, model, time, animate) {
        const wrap = document.createElement('div');
        wrap.className = 'ai-msg-wrap ' + (role === 'user' ? 'user' : 'bot');
        if (animate) { wrap.style.opacity = '0'; wrap.style.transform = 'translateY(8px)'; wrap.style.transition = 'all .2s'; }

        const meta = document.createElement('div');
        meta.className = 'ai-msg-meta';
        if (role !== 'user') {
            const tag = document.createElement('span');
            tag.className = 'ai-model-tag';
            tag.textContent = friendlyModel(model);
            meta.appendChild(tag);
        }
        const ts = document.createElement('span');
        ts.textContent = formatTime(time);
        meta.appendChild(ts);
        wrap.appendChild(meta);

        const bubble = document.createElement('div');
        bubble.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');
        if (role !== 'user' && typeof marked !== 'undefined') {
            bubble.innerHTML = marked.parse(content);
            applyCodeEnhancements(bubble);
        } else {
            bubble.textContent = content;
        }
        wrap.appendChild(bubble);

        const actions = document.createElement('div');
        actions.className = 'ai-msg-actions';
        const copyBtn = document.createElement('button');
        copyBtn.className = 'ai-copy-btn';
        copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
        copyBtn.onclick = () => copyText(content, copyBtn);
        actions.appendChild(copyBtn);
        wrap.appendChild(actions);

        messages.appendChild(wrap);
        if (animate) requestAnimationFrame(() => { wrap.style.opacity = '1'; wrap.style.transform = 'translateY(0)'; });
        scrollBottom();
        return wrap;
    }

    function appendTyping() {
        const wrap = document.createElement('div');
        wrap.className = 'ai-typing-wrap';
        const meta = document.createElement('div');
        meta.className = 'ai-msg-meta'; meta.textContent = 'AI is thinking…';
        wrap.appendChild(meta);
        const t = document.createElement('div');
        t.className = 'ai-typing';
        t.innerHTML = '<div class="ai-dots"><span></span><span></span><span></span></div>';
        wrap.appendChild(t);
        messages.appendChild(wrap);
        scrollBottom();
        return wrap;
    }

    function appendError(msg) {
        const div = document.createElement('div');
        div.style.cssText = 'text-align:center;font-size:12px;color:#ef4444;padding:6px 12px;background:#fef2f2;border-radius:8px;border:1px solid #fca5a5;';
        div.textContent = '⚠ ' + msg;
        messages.appendChild(div);
        scrollBottom();
    }

    /* Clear */
    window.clearAiChat = function () {
        if (!history.length || !confirm('Clear the entire conversation?')) return;
        history = []; saveHistory();
        messages.innerHTML = '';
        messages.appendChild(emptyState);
        sugg.style.display = ''; modelPill.textContent = '—'; updateMsgCount();
    };

    /* Export */
    window.exportAiChat = function () {
        if (!history.length) return;
        const lines = history.map(h =>
            '[' + formatTime(h.time) + '] ' +
            (h.role === 'user' ? 'You' : 'AI' + (h.model ? ' (' + h.model + ')' : '')) +
            ':\n' + h.content + '\n'
        );
        const blob = new Blob([lines.join('\n')], { type: 'text/plain' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'ai-chat-' + new Date().toISOString().slice(0,10) + '.txt';
        a.click();
    };

    /* Helpers */
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
            wrapper.className = 'ai-code-wrap';
            pre.parentNode.insertBefore(wrapper, pre);
            wrapper.appendChild(pre);

            const header = document.createElement('div');
            header.className = 'ai-code-header';
            const langSpan = document.createElement('span');
            langSpan.className = 'ai-code-lang';
            langSpan.textContent = lang;
            const copyBtn = document.createElement('button');
            copyBtn.className = 'ai-code-copy';
            copyBtn.type = 'button';
            copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
            copyBtn.addEventListener('click', function () {
                const ok  = '<i class="bi bi-check2"></i> Copied!';
                const def = '<i class="bi bi-clipboard"></i> Copy';
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

    function scrollBottom() { setTimeout(() => messages.scrollTop = messages.scrollHeight, 30); }
    function updateModelPill(model) { if (model) modelPill.textContent = friendlyModel(model); }
    function updateMsgCount() { msgCount.textContent = history.length + ' message' + (history.length !== 1 ? 's' : ''); }

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

    function autoResize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    function updateCharCount() {
        const len = input.value.length;
        charCount.textContent = len + ' / ' + MAX_CHARS;
        charCount.className = len > MAX_CHARS ? 'over' : len > MAX_CHARS * 0.85 ? 'warn' : '';
    }

    input.addEventListener('input', () => { autoResize(); updateCharCount(); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendAiMessage(); }
    });
})();
</script>
