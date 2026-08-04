<?php

namespace App\Support\Jira;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The slice of Jira Cloud's REST API an import needs.
 *
 * Authentication is an Atlassian API token used as a basic-auth password
 * against the account's email address; the token belongs in the environment,
 * never in the repository.
 */
class JiraClient
{
    /**
     * Every field a Jira board shows, custom ones included — a team's real
     * data often lives in a field they added themselves, and on the project
     * this was written for it did.
     *
     * Not `*all`: that adds comment and worklog bodies to every issue in every
     * page, and comments are fetched properly, one issue at a time.
     */
    private const FIELDS = ['*navigable'];

    /** @var array<string, string>|null field id => human name */
    private ?array $fieldNames = null;

    public function __construct(
        private readonly string $site,
        private readonly string $user,
        private readonly string $token,
    ) {}

    /**
     * @throws RuntimeException when the credentials have not been configured
     */
    public static function fromConfig(): self
    {
        $site = rtrim((string) config('services.jira.url'), '/');
        $user = trim((string) config('services.jira.user'));
        $token = (string) config('services.jira.token');

        if ($site === '' || $user === '' || $token === '') {
            throw new RuntimeException(
                'Jira is not configured. Set JIRA_URL, JIRA_USER and JIRA_TOKEN.'
            );
        }

        return new self($site, $user, $token);
    }

    public function site(): string
    {
        return $this->site;
    }

    /**
     * Who the token belongs to — the cheapest way to prove it still works.
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->get('/rest/api/3/myself');
    }

    /**
     * What every field id on this site is actually called.
     *
     * Issues come back keyed by `customfield_10216`; a person reading the
     * imported task needs to see "Acquirer".
     *
     * @return array<string, string>
     */
    public function fieldNames(): array
    {
        if ($this->fieldNames !== null) {
            return $this->fieldNames;
        }

        $names = [];

        foreach ($this->get('/rest/api/3/field') as $field) {
            if (is_array($field) && isset($field['id'])) {
                $names[(string) $field['id']] = (string) ($field['name'] ?? $field['id']);
            }
        }

        return $this->fieldNames = $names;
    }

    /**
     * @return array<string, mixed>
     */
    public function project(string $key): array
    {
        return $this->get('/rest/api/3/project/'.rawurlencode($key));
    }

    /**
     * Every issue in a project, oldest first, a page at a time.
     *
     * Yielded rather than returned: a busy project is thousands of issues and
     * there is no reason to hold them all in memory at once.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function issues(string $projectKey): Generator
    {
        $token = null;

        do {
            $query = [
                'jql' => 'project = "'.$projectKey.'" ORDER BY created ASC',
                'maxResults' => 100,
                'fields' => implode(',', self::FIELDS),
                // Jira renders its own document format to HTML far better than
                // we can; the converter is only there for when it doesn't.
                'expand' => 'renderedFields',
            ];

            if ($token !== null) {
                $query['nextPageToken'] = $token;
            }

            // The old /rest/api/3/search is deprecated and gone from newer
            // sites; /search/jql pages with an opaque token instead of an
            // offset.
            $page = $this->get('/rest/api/3/search/jql', $query);

            foreach ($page['issues'] ?? [] as $issue) {
                yield $issue;
            }

            $token = $page['nextPageToken'] ?? null;
        } while ($token !== null);
    }

    /**
     * An issue's comments, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function comments(string $issueKey): array
    {
        $comments = [];
        $startAt = 0;

        do {
            $page = $this->get('/rest/api/3/issue/'.rawurlencode($issueKey).'/comment', [
                'startAt' => $startAt,
                'maxResults' => 100,
                'orderBy' => 'created',
                'expand' => 'renderedBody',
            ]);

            $batch = $page['comments'] ?? [];
            $comments = array_merge($comments, $batch);
            $startAt += count($batch);
        } while ($batch !== [] && $startAt < (int) ($page['total'] ?? 0));

        return $comments;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($this->site.$path, $query);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Jira returned %d for %s: %s',
                $response->status(),
                $path,
                // Trimmed: Jira answers an expired token with a full HTML page.
                mb_substr(strip_tags($response->body()), 0, 300)
            ));
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth($this->user, $this->token)
            ->acceptJson()
            ->timeout(30)
            ->retry(3, 500, throw: false);
    }
}
