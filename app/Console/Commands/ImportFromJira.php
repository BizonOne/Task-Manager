<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Jira\Importer;
use App\Support\Jira\JiraClient;
use App\Support\Jira\Report;
use App\Support\Jira\StatusMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring a Jira project into this app.
 *
 *   php artisan jira:import AO --owner=ks@bizon.one --dry-run
 *   php artisan jira:import AO --owner=ks@bizon.one \
 *       --map="Acquirer=in_review" --map="Company=in_progress"
 *
 * Run it with --dry-run first. The rehearsal reads Jira and writes nothing,
 * and it answers the two questions worth answering before an import: whose
 * work has nowhere to land, and which columns this will add to everybody's
 * board.
 */
class ImportFromJira extends Command
{
    protected $signature = 'jira:import
                            {project : The Jira project key, e.g. AO}
                            {--owner= : Email or id of the person who owns the imported project}
                            {--map=* : Put a Jira status in a particular column, e.g. --map="Acquirer=in_review"}
                            {--user=* : Match a Jira email to an account here, e.g. --user="old@x.com=new@y.com"}
                            {--no-new-statuses : Fold unknown statuses onto existing columns instead of adding new ones}
                            {--refresh : Bring changes across for issues that were already imported}
                            {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Import a Jira project, its issues and their comments';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $jira = JiraClient::fromConfig();
            $owner = $this->owner();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $me = $jira->me();
        $this->line(sprintf(
            'Connected to %s as %s.',
            $jira->site(),
            $me['displayName'] ?? 'an unnamed account'
        ));
        $this->line('Imported work will belong to '.$owner->name.' <'.$owner->email.'>.');
        $this->newLine();

        $importer = new Importer(
            $jira,
            new StatusMap($this->statusOverrides(), mayCreate: ! $this->option('no-new-statuses')),
            $owner,
            dryRun: $dryRun,
            refresh: (bool) $this->option('refresh'),
            userOverrides: $this->userOverrides(),
        );

        $project = (string) $this->argument('project');

        try {
            // One transaction: a run that dies halfway through leaves a project
            // with some of its issues in it, and no way to tell which.
            $report = $dryRun
                ? $importer->run($project)
                : DB::transaction(fn () => $importer->run($project));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($report, $dryRun);

        return self::SUCCESS;
    }

    private function report(Report $report, bool $dryRun): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Project</>', $report->projectName.' ('.$report->projectAction.')');
        $this->components->twoColumnDetail('Tasks created', (string) $report->tasksCreated);
        $this->components->twoColumnDetail('Tasks updated', (string) $report->tasksUpdated);
        $this->components->twoColumnDetail('Tasks already here', (string) $report->tasksSkipped);
        $this->components->twoColumnDetail('Comments created', (string) $report->commentsCreated);
        $this->components->twoColumnDetail('Comments already here', (string) $report->commentsSkipped);
        $this->newLine();

        if ($report->statuses !== []) {
            $this->line('<fg=cyan>Statuses</>');
            foreach ($report->statuses as $jira => $key) {
                $new = in_array($jira, $report->newStatuses, true) ? ' <fg=yellow>(new column)</>' : '';
                $this->components->twoColumnDetail('  '.$jira, $key.$new);
            }
            $this->newLine();
        }

        if ($report->people !== []) {
            $this->line('<fg=cyan>People</>');
            foreach ($report->people as $jira => $here) {
                $this->components->twoColumnDetail('  '.$jira, $here);
            }
            $this->newLine();
        }

        foreach ($report->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($dryRun) {
            $this->newLine();
            $this->components->info('Dry run: nothing was written. Drop --dry-run to import.');
        } else {
            $this->newLine();
            $this->components->info('Import complete. Everything above was written.');
        }
    }

    /**
     * @throws \RuntimeException when the named owner does not exist
     */
    private function owner(): User
    {
        $given = trim((string) $this->option('owner'));

        if ($given === '') {
            throw new \RuntimeException('Say who owns the imported project: --owner=someone@example.com');
        }

        $owner = ctype_digit($given)
            ? User::find((int) $given)
            : User::whereRaw('LOWER(email) = ?', [mb_strtolower($given)])->first();

        if ($owner === null) {
            throw new \RuntimeException('No user here matches --owner='.$given);
        }

        return $owner;
    }

    /**
     * @return array<string, string>
     */
    private function statusOverrides(): array
    {
        return $this->pairs((array) $this->option('map'), '--map');
    }

    /**
     * @return array<string, string>
     */
    private function userOverrides(): array
    {
        $pairs = $this->pairs((array) $this->option('user'), '--user');

        return array_combine(
            array_map('mb_strtolower', array_keys($pairs)),
            array_values($pairs)
        );
    }

    /**
     * "left=right" pairs from a repeated option.
     *
     * @param  array<int, string>  $values
     * @return array<string, string>
     */
    private function pairs(array $values, string $option): array
    {
        $pairs = [];

        foreach ($values as $value) {
            if (! str_contains((string) $value, '=')) {
                $this->components->warn("Ignoring {$option}=\"{$value}\": expected the form left=right.");

                continue;
            }

            [$left, $right] = explode('=', (string) $value, 2);
            $pairs[trim($left)] = trim($right);
        }

        return $pairs;
    }
}
