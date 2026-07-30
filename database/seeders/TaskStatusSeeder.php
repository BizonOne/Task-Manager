<?php

namespace Database\Seeders;

use App\Models\TaskStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds the built-in task statuses. Idempotent and factory-free: only fills in
 * a status when its key is missing, so it never overwrites labels, colours or
 * ordering an admin has customised — safe to run on every deploy.
 */
class TaskStatusSeeder extends Seeder
{
    public function run(): void
    {
        foreach (TaskStatus::defaults() as $status) {
            TaskStatus::firstOrCreate(['key' => $status['key']], $status);
        }

        TaskStatus::forgetCached();

        $this->command?->info('Task statuses ready.');
    }
}
