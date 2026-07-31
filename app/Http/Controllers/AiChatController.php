<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Services\Ai\Assistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // Previously unvalidated, so a long label hit a DB error.
        $request->validate(['label' => 'nullable|string|max:120']);

        $conv = AiConversation::create([
            'user_id' => Auth::id(),
            'label' => $request->input('label') ?: 'New Chat',
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

    /* ── Chat ── */

    /**
     * Answer a message and stream the reply back as server-sent events.
     *
     * The assistant runs its tool calls server-side first, so the stream
     * carries progress events while that happens and then the finished answer
     * in chunks. History is rebuilt from the database, never from the client —
     * the browser used to post its own transcript, which let a user forge
     * assistant turns to steer the model.
     */
    public function stream(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        $message = $validated['message'];

        $conversation = $this->resolveConversation($user->id, $validated['conversation_id'] ?? null, $message);
        $conversation->messages()->create(['role' => 'user', 'content' => $message]);

        return response()->stream(function () use ($user, $message, $conversation) {
            $send = function (string $event, array $data): void {
                echo 'data: '.json_encode(['event' => $event] + $data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            $send('start', ['conversation_id' => $conversation->id]);

            $assistant = Assistant::for($user);

            if (! $assistant->isConfigured()) {
                $send('error', ['message' => 'The assistant is not configured yet. Ask an administrator to add the API key.']);
                $send('done', []);

                return;
            }

            try {
                $result = $assistant->reply(
                    $message,
                    $conversation,
                    fn (string $event, array $data) => $send($event, $data),
                );

                $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $result['text'],
                    'model' => $result['model'],
                ]);
                $conversation->touch();

                // Chunk the finished answer so it types out rather than
                // landing all at once.
                foreach ($this->chunk($result['text']) as $piece) {
                    $send('delta', ['text' => $piece]);
                }

                $send('done', ['model' => $result['model']]);
            } catch (\Throwable $e) {
                Log::error('Lina failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                // Upstream messages can name the account or the key, so keep
                // the detail in the log and show the user something safe.
                $send('error', ['message' => 'Lina could not answer that right now. Please try again in a moment.']);
                $send('done', []);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function resolveConversation(int $userId, ?int $id, string $firstMessage): AiConversation
    {
        if ($id !== null) {
            $existing = AiConversation::where('user_id', $userId)->find($id);

            if ($existing !== null) {
                return $existing;
            }
        }

        return AiConversation::create([
            'user_id' => $userId,
            'label' => Str::limit($firstMessage, 60),
        ]);
    }

    /**
     * Split text into small pieces on whitespace, so multibyte characters are
     * never cut in half mid-stream.
     *
     * @return array<int, string>
     */
    private function chunk(string $text, int $size = 24): array
    {
        $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $chunks = [];
        $buffer = '';

        foreach ($words as $word) {
            $buffer .= $word;

            if (mb_strlen($buffer) >= $size) {
                $chunks[] = $buffer;
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }
}
