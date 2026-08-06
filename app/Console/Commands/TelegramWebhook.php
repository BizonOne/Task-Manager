<?php

namespace App\Console\Commands;

use App\Support\Notifications\Telegram;
use Illuminate\Console\Command;

/**
 * Point Telegram at this app, or ask it where it is currently pointing.
 *
 *   php artisan telegram:webhook          # what does Telegram think?
 *   php artisan telegram:webhook --set    # point it here
 */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook
                            {--set : Point Telegram at this app}
                            {--url= : Override the URL (defaults to APP_URL)}';

    protected $description = 'Show or set the Telegram webhook';

    public function handle(): int
    {
        if (! Telegram::configured()) {
            $this->error('Telegram is not configured. Set TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        $me = Telegram::me();
        $this->line('Bot: @'.($me['result']['username'] ?? '?'));

        if (! $this->option('set')) {
            $info = Telegram::webhookInfo()['result'] ?? [];
            $this->components->twoColumnDetail('URL', $info['url'] ?: '<not set>');
            $this->components->twoColumnDetail('Pending updates', (string) ($info['pending_update_count'] ?? 0));
            $this->components->twoColumnDetail('Last error', $info['last_error_message'] ?? '—');

            return self::SUCCESS;
        }

        $secret = trim((string) config('services.telegram.webhook_secret'));

        if ($secret === '') {
            // Without it the endpoint would take anybody's word for what
            // Telegram said, and connecting is exactly the thing worth faking.
            $this->error('Set TELEGRAM_WEBHOOK_SECRET first — the webhook is public and that secret is what guards it.');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/telegram/webhook';
        $result = Telegram::setWebhook($url, $secret);

        if (($result['ok'] ?? false) !== true) {
            $this->error($result['description'] ?? 'Telegram refused the webhook.');

            return self::FAILURE;
        }

        $this->components->info('Webhook set to '.$url);

        return self::SUCCESS;
    }
}
