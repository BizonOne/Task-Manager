<?php

namespace App\Console\Commands;

use App\Support\Archive;
use Illuminate\Console\Command;

class ArchiveCompletedTasks extends Command
{
    protected $signature = 'tasks:archive
                            {--days= : Override the configured window}
                            {--dry-run : Report what would move without touching anything}';

    protected $description = 'Move tasks finished longer ago than the configured window into the archive';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : Archive::afterDays();

        if ($days === null || $days <= 0) {
            $this->info('Automatic archiving is off (archive window is 0). Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $due = Archive::due($days)->count();
            $this->info("{$due} task(s) finished more than {$days} day(s) ago would be archived.");

            return self::SUCCESS;
        }

        $archived = Archive::sweep($days);

        $this->info($archived === 0
            ? "Nothing finished more than {$days} day(s) ago is still on the boards."
            : "Archived {$archived} task(s) finished more than {$days} day(s) ago.");

        return self::SUCCESS;
    }
}
