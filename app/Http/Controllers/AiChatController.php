<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\File;
use App\Models\Note;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Routine;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Services\LinaFallbackBrain;

class AiChatController extends Controller
{
    public function index()
    {
        return view('ai.index');
    }

    /* ── Conversation CRUD ── */

    public function conversations()
    {
        $convs = AiConversation::where('user_id', Auth::id())
            ->orderByDesc('updated_at')
            ->get(['id', 'label', 'updated_at']);
        return response()->json($convs);
    }

    public function createConversation(Request $request)
    {
        $conv = AiConversation::create([
            'user_id' => Auth::id(),
            'label'   => $request->input('label', 'New Chat'),
        ]);
        return response()->json($conv);
    }

    public function getConversation(AiConversation $conversation)
    {
        abort_if($conversation->user_id !== Auth::id(), 403);
        $conversation->load('messages');
        return response()->json($conversation);
    }

    public function renameConversation(Request $request, AiConversation $conversation)
    {
        abort_if($conversation->user_id !== Auth::id(), 403);
        $request->validate(['label' => 'required|string|max:120']);
        $conversation->update(['label' => $request->label]);
        return response()->json(['ok' => true]);
    }

    public function deleteConversation(AiConversation $conversation)
    {
        abort_if($conversation->user_id !== Auth::id(), 403);
        $conversation->delete();
        return response()->json(['ok' => true]);
    }

    public function clearConversation(AiConversation $conversation)
    {
        abort_if($conversation->user_id !== Auth::id(), 403);
        $conversation->messages()->delete();
        $conversation->update(['label' => 'New Chat']);
        return response()->json(['ok' => true]);
    }

    // compound-beta has built-in web search (Groq agentic model)
    // Falls back to plain LLMs when rate-limited
    private array $models = [
        'compound-beta',                               // web search + coding, agentic
        'llama-3.1-8b-instant',                        // 14.4K/day fast fallback
        'meta-llama/llama-4-scout-17b-16e-instruct',   // 1K/day
        'llama-3.3-70b-versatile',                     // 1K/day, smarter
        'qwen/qwen3-32b',                              // 1K/day
    ];

    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array|max:40',
            'history.*.role'    => 'required|in:user,assistant',
            'history.*.content' => 'required|string|max:4000',
        ]);

        $apiKey = config('services.groq.key');
        if (!$apiKey) {
            return response()->json(['reply' => 'AI assistant is not configured. Please add GROQ_API_KEY to your environment.'], 200);
        }

        $user = Auth::user();

        try {
            $context = $this->buildContext($user);
        } catch (\Exception $e) {
            \Log::error('Groq buildContext error', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            $context = '(Could not load user data)';
        }

        $today = now()->format('l, F j, Y');

        $creatorName = $user->name;

        $systemPrompt = <<<PROMPT
You are Lina, a smart personal AI assistant built into this Task Manager app by {$creatorName}.
If asked your name, say your name is Lina. If asked who created or built you, say you were created by {$creatorName}.
Today is {$today}.

You can help the user with:
- Their workspace data: tasks, projects, notes, reminders, and routines (full data provided below)
- Coding, programming, software development, and any technical / technology questions
- General knowledge and current information (search the web when needed for up-to-date facts)

Guidelines:
- Use markdown formatting — bullet points, code blocks, bold headings where helpful
- For code, always use fenced code blocks with the language specified
- For workspace data, only refer to what is in the context below — do not invent data
- For tech/coding/general questions, use your training knowledge and web search as needed
- Be concise and practical

--- USER WORKSPACE DATA ---
{$context}
--- END WORKSPACE DATA ---
PROMPT;

        // Build multi-turn messages: system + history + new user message
        $messages = [['role' => 'system', 'content' => $this->cleanUtf8($systemPrompt)]];
        foreach ($request->input('history', []) as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $this->cleanUtf8($turn['content'])];
        }
        $messages[] = ['role' => 'user', 'content' => $this->cleanUtf8($request->message)];

        $lastError = 'No models available.';
        $startedAt = microtime(true);

        foreach ($this->models as $model) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])->withOptions(['verify' => false])->timeout(40)
                  ->post('https://api.groq.com/openai/v1/chat/completions', [
                      'model'       => $model,
                      'messages'    => $messages,
                      'max_tokens'  => 2048,
                      'temperature' => 0.7,
                  ]);
            } catch (\Exception $e) {
                \Log::warning('Groq connection error', ['model' => $model, 'error' => $e->getMessage()]);
                $lastError = $e->getMessage();
                continue;
            }

            if ($response->status() === 429 || $response->status() === 503) {
                \Log::info('Groq rate limited, trying next model', ['model' => $model, 'status' => $response->status()]);
                $lastError = $response->json('error.message') ?? "Rate limited on {$model}";
                continue;
            }

            // 400 bad-request means bad payload — no point retrying other models with same payload
            if ($response->status() === 400) {
                $errMsg = $response->json('error.message') ?? 'Bad request';
                \Log::error('Groq 400 bad request', ['model' => $model, 'error' => $errMsg, 'body' => $response->body()]);
                return response()->json(['reply' => "AI error (400): {$errMsg}"], 200);
            }

            if ($response->failed()) {
                $lastError = $response->json('error.message') ?? "HTTP {$response->status()} on {$model}";
                \Log::warning('Groq model failed', ['model' => $model, 'status' => $response->status(), 'error' => $lastError]);
                continue;
            }

            $reply   = trim($response->json('choices.0.message.content') ?? 'No response received.');
            $elapsed = round((microtime(true) - $startedAt) * 1000);
            $tokens  = $response->json('usage.total_tokens') ?? 0;

            \Log::info('Groq response', [
                'user_id' => $user->id,
                'model'   => $model,
                'tokens'  => $tokens,
                'ms'      => $elapsed,
                'turns'   => count($messages),
            ]);

            return response()->json(['reply' => $reply, 'model' => $model]);
        }

        \Log::error('All Groq models exhausted', ['user_id' => $user->id, 'last_error' => $lastError]);
        return response()->json(['reply' => "All AI models failed. Last error: {$lastError}"], 200);
    }

    private function sseFlush(): void
    {
        if (ob_get_level() > 0) ob_flush();
        flush();
    }

    private function cleanUtf8(string $str): string
    {
        // Replace invalid UTF-8 sequences with a placeholder, then strip nulls
        $clean = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
    }

    private function buildContext($user): string
    {
        // Projects
        $projects = Project::where('user_id', $user->id)->get(['name', 'status', 'end_date', 'budget']);
        $projectLines = $projects->map(fn($p) =>
            "- {$p->name} (status: {$p->status}" . ($p->end_date ? ", due: {$p->end_date->format('Y-m-d')}" : '') . ")"
        )->join("\n");

        // Tasks
        $tasks = Task::where('user_id', $user->id)->with('project:id,name')->get(['title', 'status', 'priority', 'due_date', 'project_id']);
        $taskLines = $tasks->map(fn($t) =>
            "- [{$t->status}] {$t->title} (priority: {$t->priority}" .
            ($t->due_date ? ", due: {$t->due_date}" : '') .
            ($t->project ? ", project: {$t->project->name}" : '') . ")"
        )->join("\n");

        // Notes
        $notes = Note::where('user_id', $user->id)->get(['title', 'content', 'tags']);
        $noteLines = $notes->map(function ($n) {
            $tags = is_array($n->tags) ? implode(', ', $n->tags) : ($n->tags ?? '');
            return "- {$n->title}" . ($tags ? " [tags: {$tags}]" : '') . ": " . strip_tags(substr($n->content ?? '', 0, 120));
        })->join("\n");

        // Reminders
        $reminders = Reminder::where('user_id', $user->id)->get(['title', 'date', 'time', 'priority', 'is_completed', 'tags']);
        $reminderLines = $reminders->map(function ($r) {
            $tags = is_array($r->tags) ? implode(', ', $r->tags) : ($r->tags ?? '');
            $status = $r->is_completed ? 'done' : 'pending';
            $when = $r->date ? $r->date->format('Y-m-d') . ($r->time ? " {$r->time}" : '') : '';
            return "- [{$status}] {$r->title}" . ($when ? " at {$when}" : '') . ($tags ? " [tags: {$tags}]" : '');
        })->join("\n");

        // Routines
        $routines = Routine::where('user_id', $user->id)->get(['title', 'frequency']);
        $routineLines = $routines->map(fn($r) => "- {$r->title} ({$r->frequency})")->join("\n");

        // Files
        $files = File::where('user_id', $user->id)->get(['name', 'type']);
        $fileLines = $files->map(fn($f) => "- {$f->name} (type: {$f->type})")->join("\n");

        return implode("\n\n", array_filter([
            $projects->count()  ? "PROJECTS ({$projects->count()}):\n{$projectLines}"    : null,
            $tasks->count()     ? "TASKS ({$tasks->count()}):\n{$taskLines}"              : null,
            $notes->count()     ? "NOTES ({$notes->count()}):\n{$noteLines}"              : null,
            $reminders->count() ? "REMINDERS ({$reminders->count()}):\n{$reminderLines}"  : null,
            $routines->count()  ? "ROUTINES ({$routines->count()}):\n{$routineLines}"     : null,
            $files->count()     ? "FILES ({$files->count()}):\n{$fileLines}"              : null,
        ]));
    }

    public function stream(Request $request)
    {
        $request->validate([
            'message'         => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer',
            'history'         => 'nullable|array|max:40',
            'history.*.role'    => 'required|in:user,assistant',
            'history.*.content' => 'required|string|max:4000',
        ]);

        $apiKey = config('services.groq.key');
        $user   = Auth::user();

        // Resolve or create conversation
        $convId = $request->input('conversation_id');
        if ($convId) {
            $conversation = AiConversation::where('id', $convId)->where('user_id', $user->id)->first();
        }
        if (empty($conversation)) {
            $conversation = AiConversation::create(['user_id' => $user->id, 'label' => 'New Chat']);
        }

        // Save user message
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $request->message,
        ]);

        // Auto-label from first user message
        if ($conversation->messages()->count() === 1) {
            $conversation->update(['label' => mb_substr($request->message, 0, 60)]);
        }

        // Find a working model (skip if no API key)
        $workingModel = null;
        $groqResponse = null;

        if ($apiKey) {
            try {
                $context = $this->buildContext($user);
            } catch (\Exception $e) {
                \Log::error('Groq stream buildContext error', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                $context = '(Could not load user data)';
            }

            $today       = now()->format('l, F j, Y');
            $creatorName = $user->name;

            $systemPrompt = <<<PROMPT
You are Lina, a smart personal AI assistant built into this Task Manager app by {$creatorName}.
If asked your name, say your name is Lina. If asked who created or built you, say you were created by {$creatorName}.
Today is {$today}.

You can help the user with:
- Their workspace data: tasks, projects, notes, reminders, routines, and files (full data provided below)
- Coding, programming, software development, and any technical / technology questions
- General knowledge and current information (search the web when needed for up-to-date facts)

Guidelines:
- Use markdown formatting — bullet points, code blocks, bold headings where helpful
- For code, always use fenced code blocks with the language specified
- For workspace data, only refer to what is in the context below — do not invent data
- For tech/coding/general questions, use your training knowledge and web search as needed
- Be concise and practical

--- USER WORKSPACE DATA ---
{$context}
--- END WORKSPACE DATA ---
PROMPT;

            $messages = [['role' => 'system', 'content' => $this->cleanUtf8($systemPrompt)]];
            foreach ($request->input('history', []) as $turn) {
                $messages[] = ['role' => $turn['role'], 'content' => $this->cleanUtf8($turn['content'])];
            }
            $messages[] = ['role' => 'user', 'content' => $this->cleanUtf8($request->message)];

        $client = new \GuzzleHttp\Client(['verify' => false, 'timeout' => 60]);

        foreach ($this->models as $model) {
            try {
                $response = $client->post('https://api.groq.com/openai/v1/chat/completions', [
                    'http_errors' => false,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'       => $model,
                        'messages'    => $messages,
                        'max_tokens'  => 2048,
                        'temperature' => 0.7,
                        'stream'      => true,
                    ],
                    'stream' => true,
                ]);

                $status = $response->getStatusCode();

                if ($status === 429 || $status === 503) {
                    \Log::info('Groq stream rate limited', ['model' => $model, 'status' => $status]);
                    continue;
                }

                if ($status !== 200) {
                    \Log::warning('Groq stream non-200', ['model' => $model, 'status' => $status]);
                    continue;
                }

                $workingModel = $model;
                $groqResponse = $response;
                break;

            } catch (\Exception $e) {
                \Log::warning('Groq stream connection error', ['model' => $model, 'error' => $e->getMessage()]);
                continue;
            }
        }
        } // end if ($apiKey)

        $sseFlush = function () { if (ob_get_level() > 0) ob_flush(); flush(); };

        // No API key OR all models exhausted → use LinaFallbackBrain (smart offline mode)
        if (!$apiKey || !$workingModel) {
            $fallbackText = (new LinaFallbackBrain($user, $request->message))->respond();
            $offlineConvId = $conversation->id;
            return response()->stream(function () use ($sseFlush, $offlineConvId, $fallbackText) {
                echo "data: " . json_encode(['model' => 'lina-offline', 'conversation_id' => $offlineConvId]) . "\n\n";
                $sseFlush();
                foreach (str_split($fallbackText, 4) as $chunk) {
                    echo "data: " . json_encode(['choices' => [['delta' => ['content' => $chunk]]]]) . "\n\n";
                    $sseFlush();
                    usleep(14000);
                }
                try {
                    AiMessage::create([
                        'conversation_id' => $offlineConvId,
                        'role'            => 'assistant',
                        'content'         => $fallbackText,
                        'model'           => 'lina-offline',
                    ]);
                    AiConversation::where('id', $offlineConvId)->touch();
                } catch (\Exception $e) { /* non-fatal */ }
                echo "data: [DONE]\n\n";
                $sseFlush();
            }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no', 'Connection' => 'keep-alive']);
        }

        $body           = $groqResponse->getBody();
        $model          = $workingModel;
        $userId         = $user->id;
        $conversationId = $conversation->id;

        return response()->stream(function () use ($body, $model, $userId, $conversationId, $sseFlush) {
            // Send model + conversation_id so the frontend can track the conversation
            echo "data: " . json_encode(['model' => $model, 'conversation_id' => $conversationId]) . "\n\n";
            $sseFlush();

            $buffer          = '';
            $accumulatedText = '';

            try {
                while (!$body->eof()) {
                    $chunk  = $body->read(256);
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line   = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);
                        $line   = trim($line);

                        if ($line === '') continue;
                        if (!str_starts_with($line, 'data: ')) continue;

                        $data = substr($line, 6);

                        if ($data === '[DONE]') {
                            // Persist the assistant reply
                            if ($accumulatedText) {
                                AiMessage::create([
                                    'conversation_id' => $conversationId,
                                    'role'            => 'assistant',
                                    'content'         => $accumulatedText,
                                    'model'           => $model,
                                ]);
                                // Bump conversation updated_at so it sorts first
                                AiConversation::where('id', $conversationId)->touch();
                            }
                            echo "data: [DONE]\n\n";
                            $sseFlush();
                            return;
                        }

                        $decoded = json_decode($data, true);
                        $token   = $decoded['choices'][0]['delta']['content'] ?? '';
                        if ($token) $accumulatedText .= $token;

                        echo "data: {$data}\n\n";
                        $sseFlush();
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Groq stream read error', ['model' => $model, 'user_id' => $userId, 'error' => $e->getMessage()]);
                echo "data: " . json_encode(['error' => 'Stream interrupted.']) . "\n\n";
                $sseFlush();
            }

            echo "data: [DONE]\n\n";
            $sseFlush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
