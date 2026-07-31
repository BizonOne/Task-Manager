<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper over Google's Gemini generateContent API.
 *
 * Deliberately narrow: it knows how to send a conversation plus a tool
 * catalogue and hand back the model's reply. Deciding what to do with a
 * returned function call is the Assistant's job.
 */
class GeminiClient
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(
        private readonly ?string $key = null,
        private readonly array $models = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.gemini.key'),
            config('services.gemini.models', []),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->key) && $this->models !== [];
    }

    /**
     * Send one turn and return the raw candidate content.
     *
     * @param  array<int, array<string, mixed>>  $contents  the conversation so far
     * @param  array<int, array<string, mixed>>  $tools  function declarations
     * @return array{parts: array<int, array<string, mixed>>, model: string}
     *
     * @throws RuntimeException when every model fails
     */
    public function generate(array $contents, string $systemInstruction, array $tools = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The assistant is not configured.');
        }

        $payload = [
            'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 2048,
            ],
            // Business data, not creative writing — don't let the safety
            // filters silently drop a legitimate task title.
            'safetySettings' => array_map(
                fn (string $c): array => ['category' => $c, 'threshold' => 'BLOCK_ONLY_HIGH'],
                [
                    'HARM_CATEGORY_HARASSMENT',
                    'HARM_CATEGORY_HATE_SPEECH',
                    'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'HARM_CATEGORY_DANGEROUS_CONTENT',
                ],
            ),
        ];

        if ($tools !== []) {
            $payload['tools'] = [['function_declarations' => $tools]];
        }

        $lastError = null;

        foreach ($this->models as $model) {
            try {
                $response = Http::timeout(45)
                    ->connectTimeout(10)
                    // TLS verification stays on: this request carries the API
                    // key and the user's workspace data.
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post(sprintf(self::ENDPOINT, $model).'?key='.urlencode($this->key), $payload);

                if ($response->successful()) {
                    $parts = $response->json('candidates.0.content.parts');

                    if (is_array($parts)) {
                        return ['parts' => $parts, 'model' => $model];
                    }

                    // A blocked or empty candidate — treat as a failure so the
                    // next model gets a turn.
                    $lastError = 'empty response from '.$model;

                    continue;
                }

                // 429/5xx are worth retrying on another model; a 400 usually
                // means the model rejected the request shape, so also move on.
                $lastError = $model.' returned HTTP '.$response->status();
                Log::warning('Gemini request failed', [
                    'model' => $model,
                    'status' => $response->status(),
                ]);
            } catch (\Throwable $e) {
                $lastError = $model.': '.$e->getMessage();
                Log::warning('Gemini request threw', ['model' => $model, 'error' => $e->getMessage()]);
            }
        }

        // The upstream message may name the account or key — keep it in the
        // log, not in the user's chat window.
        throw new RuntimeException('The assistant is unavailable right now. ('.$lastError.')');
    }
}
