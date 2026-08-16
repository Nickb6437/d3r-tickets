<?php

namespace D3R\Tickets;

/**
 * Jira Cloud client: your issues, the tickets your branches name, and the development
 * panel that links both to branches and pull requests.
 */
class Jira
{
    private const string FIELDS = 'summary,status,updated,assignee';

    private const int PAGE_SIZE = 100;

    private string $base;

    private string $auth;

    /** @var string[] */
    private array $warnings = [];

    private ?string $applicationType = null;

    public function __construct(private Config $config)
    {
        $this->base = 'https://' . $config->get('jira', 'host');
        $this->auth = base64_encode($config->get('jira', 'email') . ':' . $config->get('jira', 'token'));
    }

    /**
     * Everything Jira can tell us, in one round trip.
     *
     * The three requests are independent, so they go out together rather than in turn.
     *
     * @param  int      $sinceDays    Only issues touched in this window, 0 for no bound.
     * @param  string[] $clauses      Extra JQL clauses, ANDed together.
     * @param  bool     $assignedOnly Naming a specific ticket should find it either way.
     * @param  string[] $keys         Ticket keys your branches name, whoever owns them.
     * @return array{issues:array<int,array>,tickets:array<string,array>,accountId:string}
     */
    public function fetch(int $sinceDays, array $clauses, bool $assignedOnly, array $keys): array
    {
        $keys = array_values(array_unique(array_filter($keys)));

        $requests = [
            'issues' => $this->searchPath($this->jql($sinceDays, $clauses, $assignedOnly)),
            'me' => '/rest/api/3/myself',
        ];

        if ($keys !== []) {
            $requests['tickets'] = $this->searchPath($this->jql(0, [sprintf('key in (%s)', implode(', ', $keys))], false));
        }

        $responses = $this->multiGet($requests);
        $issues = $this->issues($responses['issues'] ?? null);

        if (count($issues) >= self::PAGE_SIZE) {
            $this->warnings[] = sprintf(
                'Jira returned its first %d tickets and there may be more. Narrow with --active or --since.',
                self::PAGE_SIZE
            );
        }

        return [
            'issues' => $issues,
            'tickets' => array_column($this->issues($responses['tickets'] ?? null), null, 'key'),
            'accountId' => (string) ($responses['me']['accountId'] ?? ''),
        ];
    }

    /**
     * Branches and pull requests linked to each issue, via the development panel.
     *
     * Asking for pullrequest data returns the branches too, and unlike a GitHub search
     * by author it covers every pull request on the ticket, whoever opened it.
     * Undocumented endpoint, so a miss is not fatal: callers fall back to key matching.
     *
     * @param  string[] $issueIds
     * @return array<string,array{branches:string[],pullRequests:array<int,array{number:int,branch:string,repo:string,state:string,title:string,url:string}>}>
     */
    public function development(array $issueIds): array
    {
        if ($issueIds === []) {
            return [];
        }

        $applicationType = $this->applicationType((string) reset($issueIds));

        $requests = [];
        foreach ($issueIds as $id) {
            $requests[$id] = '/rest/dev-status/latest/issue/detail?' . http_build_query([
                'issueId' => $id,
                'applicationType' => $applicationType,
                'dataType' => 'pullrequest',
            ]);
        }

        $development = [];
        foreach ($this->multiGet($requests) as $id => $response) {
            $detail = $response['detail'][0] ?? [];

            $branches = array_values(array_filter(array_map(
                static fn(array $branch) => (string) ($branch['name'] ?? ''),
                $detail['branches'] ?? []
            )));

            $pullRequests = array_map(
                fn(array $pullRequest) => [
                    'number' => (int) ltrim((string) ($pullRequest['id'] ?? ''), '#'),
                    'branch' => (string) ($pullRequest['source']['branch'] ?? ''),
                    'repo' => $this->repoFromUrl((string) ($pullRequest['url'] ?? '')),
                    // Jira says declined where GitHub says closed.
                    'state' => str_replace(
                        'declined',
                        'closed',
                        strtolower((string) ($pullRequest['status'] ?? ''))
                    ),
                    'title' => (string) ($pullRequest['name'] ?? ''),
                    'url' => (string) ($pullRequest['url'] ?? ''),
                ],
                $detail['pullRequests'] ?? []
            );

            if ($branches !== [] || $pullRequests !== []) {
                $development[$id] = ['branches' => $branches, 'pullRequests' => $pullRequests];
            }
        }

        if ($development === []) {
            $this->warnings[] = 'Jira dev panel returned nothing, falling back to ticket key matching.';
        }

        return $development;
    }

    /**
     * The account the token belongs to, or an empty string if it was rejected.
     *
     * @return string
     */
    public function me(): string
    {
        return (string) ($this->multiGet(['me' => '/rest/api/3/myself'])['me']['accountId'] ?? '');
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param  int      $sinceDays
     * @param  string[] $clauses
     * @param  bool     $assignedOnly
     * @return string
     */
    private function jql(int $sinceDays, array $clauses, bool $assignedOnly): string
    {
        if ($assignedOnly) {
            array_unshift($clauses, 'assignee = currentUser()');
        }

        if ($sinceDays > 0) {
            $clauses[] = sprintf('updated >= -%dd', $sinceDays);
        }

        return implode(' AND ', $clauses) . ' ORDER BY updated DESC';
    }

    /**
     * @param  string $jql
     * @return string
     */
    private function searchPath(string $jql): string
    {
        return '/rest/api/3/search/jql?' . http_build_query([
            'jql' => $jql,
            'fields' => self::FIELDS,
            'maxResults' => self::PAGE_SIZE,
        ]);
    }

    /**
     * @param  array|null $response
     * @return array<int,array{id:string,key:string,summary:string,status:string,updated:string,assignee:string,assigneeName:string}>
     */
    private function issues(?array $response): array
    {
        return array_map(static fn(array $issue) => [
            'id' => (string) ($issue['id'] ?? ''),
            'key' => (string) ($issue['key'] ?? ''),
            'summary' => (string) ($issue['fields']['summary'] ?? ''),
            'status' => (string) ($issue['fields']['status']['name'] ?? 'Unknown'),
            'updated' => (string) ($issue['fields']['updated'] ?? ''),
            'assignee' => (string) ($issue['fields']['assignee']['accountId'] ?? ''),
            'assigneeName' => (string) ($issue['fields']['assignee']['displayName'] ?? ''),
        ], $response['issues'] ?? []);
    }

    /**
     * The dev tool instance the site links commits through.
     *
     * The detail endpoint wants the instance type verbatim, not a friendly name:
     * "GitHub" quietly returns nothing, "oAuth-com.github.integration.production" works.
     *
     * @param  string $issueId Any issue, used only to read the summary.
     * @return string
     */
    private function applicationType(string $issueId): string
    {
        if ($this->applicationType !== null) {
            return $this->applicationType;
        }

        $path = '/rest/dev-status/1.0/issue/summary?' . http_build_query(['issueId' => $issueId]);
        $summary = $this->multiGet(['summary' => $path])['summary'] ?? null;

        foreach (['branch', 'pullrequest', 'repository'] as $section) {
            $instances = array_keys($summary['summary'][$section]['byInstanceType'] ?? []);
            if ($instances !== []) {
                return $this->applicationType = (string) $instances[0];
            }
        }

        return $this->applicationType = 'GitHub';
    }

    /**
     * GET several endpoints at once, keeping the caller's keys.
     *
     * @param  array<string,string> $paths
     * @return array<string,array|null>
     */
    private function multiGet(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $multi = curl_multi_init();
        $handles = [];

        foreach ($paths as $name => $path) {
            $handle = curl_init($this->base . $path);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . $this->auth,
                    'Accept: application/json',
                ],
            ]);

            $handles[$name] = $handle;
            curl_multi_add_handle($multi, $handle);
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.1);
                Progress::tick();
            }
        } while ($running && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $name => $handle) {
            $body = curl_multi_getcontent($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_multi_remove_handle($multi, $handle);

            $responses[$name] = $code === 200 && is_string($body) ? json_decode($body, true) : null;

            if ($code === 401 || $code === 403) {
                $this->warnings['auth'] = sprintf(
                    'Jira rejected the credentials (HTTP %d). Check the token in your config.',
                    $code
                );
            } elseif ($code !== 200 && $error !== '') {
                $this->warnings[] = 'Jira request failed: ' . $error;
            }
        }

        curl_multi_close($multi);

        return $responses;
    }

    /**
     * @param  string $url
     * @return string
     */
    private function repoFromUrl(string $url): string
    {
        if (preg_match('#github\.com/([^/]+/[^/]+)/pull/#', $url, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}
