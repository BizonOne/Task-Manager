<?php

namespace App\Support\Jira;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Brings a Jira project across: the project, its issues, and the discussion
 * on each one.
 *
 * Two rules shape the whole thing.
 *
 * Nothing is invented. A Jira account that has no counterpart here does not
 * become a new user — the work lands on the person who owns the import and
 * the report says whose work it really was. Silently creating half a dozen
 * accounts nobody can log into is worse than an honest note.
 *
 * Nothing is imported twice. Every record remembers the Jira id it came from,
 * so a second run brings the comments that were added since instead of a
 * second copy of the project.
 */
class Importer
{
    public const SOURCE = 'jira';

    /** @var array<string, User|null> lowercased email or account id => user */
    private array $people = [];

    /**
     * @param  array<string, string>  $userOverrides  Jira email => app user email
     */
    public function __construct(
        private readonly JiraClient $jira,
        private readonly StatusMap $statuses,
        private readonly User $owner,
        private readonly bool $dryRun = false,
        private readonly bool $refresh = false,
        private readonly array $userOverrides = [],
    ) {}

    public function run(string $projectKey): Report
    {
        $report = new Report;

        $project = $this->project($this->jira->project($projectKey), $report);

        foreach ($this->jira->issues($projectKey) as $issue) {
            $task = $this->task($project, $issue, $report);

            $this->comments($task, $issue, $report);
        }

        $report->statuses = $this->statuses->resolved();
        $report->newStatuses = $this->statuses->created();

        return $report;
    }

    // --- the project ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $jiraProject
     */
    private function project(array $jiraProject, Report $report): ?Project
    {
        $key = (string) $jiraProject['key'];
        $report->projectName = (string) ($jiraProject['name'] ?? $key);

        $existing = Project::where('external_source', self::SOURCE)
            ->where('external_key', $key)
            ->first();

        $report->projectAction = $existing === null ? 'created' : ($this->refresh ? 'updated' : 'matched');

        if ($existing !== null && ! $this->refresh) {
            return $existing;
        }

        if ($this->dryRun) {
            return $existing;
        }

        $project = $existing ?? new Project;
        $project->user_id = $this->owner->id;
        $project->name = $report->projectName;
        $project->description = RichText::clean((string) ($jiraProject['description'] ?? '')) ?? $project->description;
        $project->status = $project->status ?? 'in_progress';
        $project->external_source = self::SOURCE;
        $project->external_key = $key;
        $project->external_url = $this->jira->site().'/browse/'.$key;
        $project->save();

        return $project;
    }

    // --- issues --------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $issue
     */
    private function task(?Project $project, array $issue, Report $report): ?Task
    {
        $key = (string) $issue['key'];
        $fields = $issue['fields'] ?? [];

        $existing = Task::where('external_source', self::SOURCE)
            ->where('external_key', $key)
            ->first();

        // Resolved either way: the report should name everyone the project
        // touches, and every column it needs, even on a run that writes
        // nothing.
        $status = $this->statuses->keyFor($fields['status'] ?? [], $this->dryRun);
        $assignee = $this->person($fields['assignee'] ?? null, $report);
        $reporter = $this->person($fields['reporter'] ?? null, $report);

        if (($fields['parent'] ?? null) !== null) {
            $report->warn(sprintf(
                '%s is a child of %s in Jira; this app has no task hierarchy, so it comes across on its own.',
                $key,
                $fields['parent']['key'] ?? '?'
            ));
        }

        if ($existing !== null && ! $this->refresh) {
            $report->tasksSkipped++;

            return $existing;
        }

        $existing === null ? $report->tasksCreated++ : $report->tasksUpdated++;

        if ($this->dryRun || $project === null) {
            return $existing;
        }

        $task = $existing ?? new Task;
        $task->project_id = $project->id;
        $task->title = Str::limit((string) ($fields['summary'] ?? $key), 250, '…');
        $task->description = $this->description($issue);
        $task->status = $status;
        $task->priority = $this->priority($fields['priority'] ?? null);
        // A plain Y-m-d string, as the column holds and the rest of the app
        // reads it — a Carbon here would store a midnight time with it.
        $task->due_date = $fields['duedate'] ?? null;
        $task->estimated_hours = $this->hours($fields['timeoriginalestimate'] ?? null);
        $task->user_id = $assignee->id;
        $task->created_by = $reporter->id;
        $task->external_source = self::SOURCE;
        $task->external_key = $key;
        $task->external_url = $this->jira->site().'/browse/'.$key;

        // Jira's own dates, not this afternoon's. A task that says it was
        // raised today when it was raised in March is a task whose history
        // reads as a lie — and the archive window counts from `completed_at`.
        $task->created_at = $this->date($fields['created'] ?? null) ?? CarbonImmutable::now();
        $task->updated_at = $this->date($fields['updated'] ?? null) ?? $task->created_at;

        if (in_array($status, TaskStatus::completedKeys(), true)) {
            $task->completed_at = $this->date($fields['resolutiondate'] ?? null) ?? $task->updated_at;
        }

        $task->save();

        // The observer puts the named assignee on the assignee list; the
        // reporter is a member of the project, not of every task they raised.
        $this->addToProject($project, $assignee);
        $this->addToProject($project, $reporter);

        return $task;
    }

    /**
     * The issue's description, with anything the app has nowhere to keep noted
     * underneath it rather than dropped.
     *
     * @param  array<string, mixed>  $issue
     */
    private function description(array $issue): ?string
    {
        $html = $this->richText(
            $issue['renderedFields']['description'] ?? null,
            $issue['fields']['description'] ?? null,
        );

        $labels = array_filter((array) ($issue['fields']['labels'] ?? []));

        if ($labels !== []) {
            $html .= '<p><em>Jira labels: '.e(implode(', ', $labels)).'</em></p>';
        }

        return RichText::clean($html);
    }

    // --- comments ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $issue
     */
    private function comments(?Task $task, array $issue, Report $report): void
    {
        $key = (string) $issue['key'];

        foreach ($this->jira->comments($key) as $comment) {
            $id = (string) ($comment['id'] ?? '');

            $author = $this->person($comment['author'] ?? null, $report);

            $existing = $id === '' ? null : TaskComment::where('external_source', self::SOURCE)
                ->where('external_key', $key.'/'.$id)
                ->first();

            if ($existing !== null) {
                $report->commentsSkipped++;

                continue;
            }

            $report->commentsCreated++;

            if ($this->dryRun || $task === null) {
                continue;
            }

            $body = RichText::clean($this->richText(
                $comment['renderedBody'] ?? null,
                $comment['body'] ?? null,
            ));

            if ($body === null) {
                $report->commentsCreated--;

                continue;
            }

            $row = new TaskComment;
            $row->task_id = $task->id;
            $row->user_id = $author->id;
            $row->body = $body;
            $row->external_source = self::SOURCE;
            $row->external_key = $key.'/'.$id;
            $row->created_at = $this->date($comment['created'] ?? null) ?? $task->created_at;
            $row->updated_at = $this->date($comment['updated'] ?? null) ?? $row->created_at;
            $row->save();
        }
    }

    // --- people --------------------------------------------------------------

    /**
     * Who a Jira account is here.
     *
     * Matched on email first — the only identifier both systems agree on —
     * then on display name, because Jira hides email addresses unless the
     * account has made them public. Whoever cannot be placed becomes the
     * person who asked for the import, and is named in the report.
     *
     * @param  array<string, mixed>|null  $account
     */
    private function person(?array $account, Report $report): User
    {
        if ($account === null) {
            return $this->owner;
        }

        $email = strtolower(trim((string) ($account['emailAddress'] ?? '')));
        $name = trim((string) ($account['displayName'] ?? ''));
        $cacheKey = $email !== '' ? $email : 'id:'.($account['accountId'] ?? $name);

        if (array_key_exists($cacheKey, $this->people)) {
            return $this->people[$cacheKey] ?? $this->owner;
        }

        $override = $this->userOverrides[$email] ?? null;
        $user = null;

        if ($override !== null) {
            $user = User::where('email', $override)->first();
        }

        if ($user === null && $email !== '') {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if ($user === null && $name !== '') {
            $user = User::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        }

        $label = $name !== '' ? $name : ($email !== '' ? $email : 'someone in Jira');

        if ($user === null) {
            $report->people[$label] = $this->owner->name.' (no account here)';
            $report->warn(sprintf(
                'No account here for %s%s — their work is filed under %s.',
                $label,
                $email !== '' ? ' <'.$email.'>' : '',
                $this->owner->name
            ));
        } else {
            $report->people[$label] = $user->name;
        }

        $this->people[$cacheKey] = $user;

        return $user ?? $this->owner;
    }

    private function addToProject(Project $project, User $user): void
    {
        if ($user->id === $project->user_id) {
            return;
        }

        $project->users()->syncWithoutDetaching([$user->id]);
    }

    // --- small conversions ---------------------------------------------------

    /**
     * Jira's rendered HTML when we have it, our own conversion when we don't.
     *
     * @param  array<string, mixed>|string|null  $adf
     */
    private function richText(?string $rendered, array|string|null $adf): ?string
    {
        if ($rendered !== null && trim(strip_tags($rendered)) !== '') {
            return $rendered;
        }

        if (is_array($adf)) {
            return Adf::toHtml($adf);
        }

        // A site still on the v2 API answers with plain text.
        return is_string($adf) && trim($adf) !== '' ? '<p>'.nl2br(e($adf)).'</p>' : null;
    }

    /**
     * @param  array<string, mixed>|null  $priority
     */
    private function priority(?array $priority): string
    {
        return match (mb_strtolower(trim((string) ($priority['name'] ?? '')))) {
            'highest', 'high', 'critical', 'blocker', 'urgent' => 'high',
            'low', 'lowest', 'trivial', 'minor' => 'low',
            default => 'medium',
        };
    }

    /**
     * Jira counts an estimate in seconds; the column holds 999.99 hours at
     * most, and an estimate that large is not an estimate.
     */
    private function hours(mixed $seconds): ?float
    {
        if (! is_numeric($seconds) || (float) $seconds <= 0) {
            return null;
        }

        return round(min((float) $seconds / 3600, 999.99), 2);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
