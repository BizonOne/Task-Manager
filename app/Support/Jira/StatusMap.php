<?php

namespace App\Support\Jira;

use App\Models\TaskStatus;
use Illuminate\Support\Str;

/**
 * Which column an imported issue lands in.
 *
 * Jira boards carry whatever columns a team invented; this app's statuses are
 * global, shared by every board in it. So the mapping matters twice over — a
 * column created for one imported project shows up as an empty column on
 * everyone else's board, and a status folded onto the wrong one loses a
 * distinction the original team cared about.
 *
 * Resolution, in order:
 *   1. an explicit instruction from the operator (--map="Acquirer=in_review")
 *   2. a status already here under that name or key
 *   3. a well-known synonym ("Done", "Closed", "Backlog"…)
 *   4. a new column, named after the Jira one — or, if the operator asked for
 *      no new columns, the closest match for the Jira status category.
 */
class StatusMap
{
    /**
     * Names Jira teams give to columns that plainly correspond to one of ours.
     * Matched against a normalised name, so "In-Progress" and "in progress"
     * both land.
     *
     * @var array<string, string>
     */
    private const SYNONYMS = [
        'todo' => 'to_do', 'to do' => 'to_do', 'open' => 'to_do', 'new' => 'to_do',
        'backlog' => 'to_do', 'selected for development' => 'to_do', 'created' => 'to_do',
        'in progress' => 'in_progress', 'inprogress' => 'in_progress',
        'doing' => 'in_progress', 'started' => 'in_progress', 'development' => 'in_progress',
        'in review' => 'in_review', 'review' => 'in_review', 'in qa' => 'in_review',
        'code review' => 'in_review', 'testing' => 'in_review', 'verification' => 'in_review',
        'on hold' => 'on_hold', 'onhold' => 'on_hold', 'paused' => 'on_hold',
        'blocked' => 'on_hold', 'waiting' => 'on_hold', 'pending' => 'on_hold',
        'done' => 'completed', 'complete' => 'completed', 'completed' => 'completed',
        'closed' => 'completed', 'resolved' => 'completed', 'finished' => 'completed',
    ];

    /**
     * The last resort: Jira's own idea of what a column means.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'new' => 'to_do',
        'indeterminate' => 'in_progress',
        'done' => 'completed',
    ];

    /** @var array<string, string> normalised Jira name => app status key */
    private array $overrides = [];

    /** @var array<string, string> Jira name => app status key, as resolved */
    private array $resolved = [];

    /** @var array<int, string> statuses this import had to invent */
    private array $created = [];

    /**
     * @param  array<string, string>  $overrides  Jira status name => app status key
     */
    public function __construct(array $overrides = [], private readonly bool $mayCreate = true)
    {
        foreach ($overrides as $name => $key) {
            $this->overrides[self::normalise($name)] = $key;
        }
    }

    /**
     * @param  array<string, mixed>  $status  Jira's status object
     */
    public function keyFor(array $status, bool $dryRun = false): string
    {
        $name = trim((string) ($status['name'] ?? ''));
        $category = strtolower((string) ($status['statusCategory']['key'] ?? 'indeterminate'));

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        return $this->resolved[$name] = $this->resolve($name, $category, $dryRun);
    }

    private function resolve(string $name, string $category, bool $dryRun): string
    {
        $normalised = self::normalise($name);

        if (isset($this->overrides[$normalised])) {
            return $this->overrides[$normalised];
        }

        foreach (TaskStatus::ordered() as $status) {
            if (self::normalise($status->label) === $normalised || $status->key === Str::snake($normalised)) {
                return $status->key;
            }
        }

        if (isset(self::SYNONYMS[$normalised])) {
            $key = self::SYNONYMS[$normalised];

            // Only if that column actually exists here — an admin is free to
            // have deleted it.
            if (TaskStatus::find_by_key($key) !== null) {
                return $key;
            }
        }

        if (! $this->mayCreate) {
            return $this->fallback($category);
        }

        $key = Str::snake(Str::ascii($normalised)) ?: 'imported';
        $this->created[] = $name;

        if ($dryRun) {
            return $key;
        }

        TaskStatus::create([
            'key' => $key,
            'label' => $name,
            'color' => $category === 'done' ? 'green' : ($category === 'new' ? 'gray' : 'teal'),
            'sort_order' => (int) TaskStatus::max('sort_order') + 1,
            'is_completed' => $category === 'done',
            'is_default' => false,
        ]);

        TaskStatus::forgetCached();

        return $key;
    }

    /**
     * The closest existing column for a Jira category, or whatever the board
     * starts new work in if even that is gone.
     */
    private function fallback(string $category): string
    {
        $key = self::CATEGORIES[$category] ?? 'to_do';

        return TaskStatus::find_by_key($key)?->key ?? TaskStatus::defaultKey();
    }

    /**
     * Jira name => app status key, for the report.
     *
     * @return array<string, string>
     */
    public function resolved(): array
    {
        return $this->resolved;
    }

    /**
     * @return array<int, string>
     */
    public function created(): array
    {
        return $this->created;
    }

    private static function normalise(string $value): string
    {
        return trim(preg_replace('/[\s_-]+/u', ' ', mb_strtolower($value)) ?? $value);
    }
}
