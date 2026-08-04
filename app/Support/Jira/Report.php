<?php

namespace App\Support\Jira;

/**
 * What an import did, or would do.
 *
 * The same object comes back from a dry run and from the real thing, so the
 * rehearsal and the performance can be compared line for line.
 */
class Report
{
    public string $projectName = '';

    /** @var 'created'|'updated'|'matched' */
    public string $projectAction = 'created';

    public int $tasksCreated = 0;

    public int $tasksUpdated = 0;

    public int $tasksSkipped = 0;

    public int $commentsCreated = 0;

    public int $commentsSkipped = 0;

    /** @var array<string, string> Jira person => who they became here */
    public array $people = [];

    /** @var array<string, string> Jira status => app status key */
    public array $statuses = [];

    /** @var array<int, string> statuses this import had to invent */
    public array $newStatuses = [];

    /** @var array<int, string> */
    public array $warnings = [];

    public function warn(string $message): void
    {
        // The same warning about the same missing person repeats once per
        // issue; the operator only needs to be told once.
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }
}
