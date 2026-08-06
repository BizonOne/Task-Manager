<?php

namespace App\Console\Commands;

use App\Support\Notifications\WebPush;
use Illuminate\Console\Command;

/**
 * A VAPID key pair, which is this application's identity to the push services.
 *
 *   php artisan webpush:keys
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:keys';

    protected $description = 'Generate a VAPID key pair for browser notifications';

    public function handle(): int
    {
        if (WebPush::configured()) {
            $this->components->warn('A key pair is already configured. Replacing it invalidates every existing subscription — everyone would have to allow notifications again.');

            if (! $this->confirm('Generate a new one anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $keys = WebPush::generateKeys();

        $this->newLine();
        $this->line('Put these in the environment:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:you@example.com');
        $this->newLine();
        $this->components->warn('The private key is a credential. It belongs in the environment and nowhere else.');

        return self::SUCCESS;
    }
}
