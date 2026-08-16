<?php

namespace D3R\Tickets;

/**
 * GitHub via the gh CLI, which is already authenticated.
 *
 * One GraphQL search covers every repo in the org, so vendor and core work
 * arrives alongside client work. Mergeability comes back in the same call,
 * ready for a future "update this branch from master" action.
 */
class GitHub
{
    private const int BRANCH_LOOKUP_LIMIT = 60;

    private const string PR_FIELDS = 'number title url state isDraft headRefName
        mergeable mergeStateStatus reviewDecision updatedAt repository { nameWithOwner }';

    /** @var string[] */
    private array $warnings = [];

    private ?Process $authored = null;

    /**
     * Start the authored search so it runs while Jira is being read.
     *
     * @param  string $org
     * @param  int    $sinceDays
     * @return void
     */
    public function prime(string $org, int $sinceDays): void
    {
        $this->authored ??= Process::start($this->authoredCommand($org, $sinceDays));
    }

    /**
     * Pull requests you authored across the org, keyed by branch name.
     *
     * @param  string $org
     * @param  int    $sinceDays
     * @return array<string,array{number:int,url:string,state:string,draft:bool,mergeable:string,mergeState:string,repo:string,title:string,updated:string}>
     */
    public function authoredPullRequests(string $org, int $sinceDays): array
    {
        $this->prime($org, $sinceDays);

        $payload = $this->read($this->authored);
        $pullRequests = [];

        foreach ($payload['data']['search']['nodes'] ?? [] as $node) {
            $branch = (string) ($node['headRefName'] ?? '');
            if ($branch === '') {
                continue;
            }

            // Search is newest first, so an existing entry is the fresher one.
            if (isset($pullRequests[$branch])) {
                continue;
            }

            $pullRequests[$branch] = $this->normalise($node);
        }

        return $pullRequests;
    }

    /**
     * Pull requests on given branches, whoever wrote them.
     *
     * A staging box often runs a ticket branch owned by someone else, with your own
     * branch merged into it, so the PR that matters is not always one of yours.
     *
     * Keyed "owner/repo#branch": the same branch name exists in more than one repo,
     * so keying on the branch alone lets one repo's pull request mask another's.
     *
     * @param  array<int,array{repo:string,branch:string}> $pairs
     * @return array<string,array>
     */
    public function pullRequestsForBranches(array $pairs): array
    {
        $pairs = array_values($pairs);
        if ($pairs === []) {
            return [];
        }

        if (count($pairs) > self::BRANCH_LOOKUP_LIMIT) {
            $this->warnings[] = sprintf(
                'Looked up pull requests for the first %d branches only, %d were skipped.',
                self::BRANCH_LOOKUP_LIMIT,
                count($pairs) - self::BRANCH_LOOKUP_LIMIT
            );
            $pairs = array_slice($pairs, 0, self::BRANCH_LOOKUP_LIMIT);
        }

        $selections = [];
        foreach ($pairs as $index => $pair) {
            [$owner, $name] = array_pad(explode('/', $pair['repo'], 2), 2, '');
            if ($name === '') {
                continue;
            }

            $selections[] = sprintf(
                'b%d: repository(owner: %s, name: %s) {
                    pullRequests(headRefName: %s, first: 5, orderBy: {field: UPDATED_AT, direction: DESC}) {
                        nodes { %s }
                    }
                }',
                $index,
                json_encode($owner),
                json_encode($name),
                json_encode($pair['branch']),
                self::PR_FIELDS
            );
        }

        if ($selections === []) {
            return [];
        }

        $payload = $this->read(Process::start($this->command('query { ' . implode("\n", $selections) . ' }')));
        $found = [];

        foreach ($payload['data'] ?? [] as $nodes) {
            foreach ($nodes['pullRequests']['nodes'] ?? [] as $node) {
                $branch = (string) ($node['headRefName'] ?? '');
                if ($branch === '') {
                    continue;
                }

                $key = ($node['repository']['nameWithOwner'] ?? '') . '#' . $branch;

                // An open pull request beats a merged one, otherwise take the newest.
                $existing = $found[$key] ?? null;
                $isOpen = ($node['state'] ?? '') === 'OPEN';
                if ($existing !== null && !($isOpen && $existing['state'] !== 'open')) {
                    continue;
                }

                $found[$key] = $this->normalise($node);
            }
        }

        return $found;
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * The gh command for the authored search.
     *
     * @param  string $org
     * @param  int    $sinceDays
     * @return string
     */
    private function authoredCommand(string $org, int $sinceDays): string
    {
        $search = sprintf('author:@me org:%s type:pr sort:updated-desc', $org);
        if ($sinceDays > 0) {
            $search .= ' updated:>=' . date('Y-m-d', strtotime(sprintf('-%d days', $sinceDays)));
        }

        $query = sprintf(
            'query($search: String!) {
                search(query: $search, type: ISSUE, first: 100) {
                    nodes { ... on PullRequest { %s } }
                }
            }',
            self::PR_FIELDS
        );

        return $this->command($query) . ' -F search=' . escapeshellarg($search);
    }

    /**
     * @param  string $query
     * @return string
     */
    private function command(string $query): string
    {
        return 'gh api graphql -f query=' . escapeshellarg($query);
    }

    /**
     * @param  Process $process
     * @return array
     */
    private function read(Process $process): array
    {
        $body = $process->output();

        if ($process->failed()) {
            $this->warnings[] = 'gh failed, so pull requests are missing: ' . $this->firstLine($body);
            return [];
        }

        return json_decode($body, true) ?: [];
    }

    /**
     * @param  array $node
     * @return array{number:int,url:string,state:string,draft:bool,mergeable:string,mergeState:string,repo:string,title:string,updated:string}
     */
    private function normalise(array $node): array
    {
        return [
            'number' => (int) ($node['number'] ?? 0),
            'url' => (string) ($node['url'] ?? ''),
            'state' => strtolower((string) ($node['state'] ?? '')),
            'draft' => (bool) ($node['isDraft'] ?? false),
            'mergeable' => (string) ($node['mergeable'] ?? 'UNKNOWN'),
            'mergeState' => (string) ($node['mergeStateStatus'] ?? 'UNKNOWN'),
            'review' => (string) ($node['reviewDecision'] ?? ''),
            'branch' => (string) ($node['headRefName'] ?? ''),
            'repo' => (string) ($node['repository']['nameWithOwner'] ?? ''),
            'title' => (string) ($node['title'] ?? ''),
            'updated' => (string) ($node['updatedAt'] ?? ''),
        ];
    }

    /**
     * @param  string $text
     * @return string
     */
    private function firstLine(string $text): string
    {
        return trim(strtok($text, "\n") ?: '');
    }
}
