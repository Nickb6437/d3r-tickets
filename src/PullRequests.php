<?php

namespace D3R\Tickets;

/**
 * Resolves each row's pull requests and decides which one leads.
 *
 * Jira names the pull requests on a ticket but not their state, so GitHub is asked
 * about those branches in one batch, after filtering, to keep the query small.
 */
final class PullRequests
{
    public function __construct(private GitHub $github)
    {
    }

    /**
     * @param  array $pullRequest
     * @return array{number:int,url:string,state:string,draft:bool,mergeable:string,mergeState:string,review:string,branch:string,repo:string,title:string}
     */
    public static function summarise(array $pullRequest): array
    {
        return [
            'number' => $pullRequest['number'],
            'url' => $pullRequest['url'],
            'state' => $pullRequest['state'],
            'draft' => $pullRequest['draft'] ?? false,
            'mergeable' => $pullRequest['mergeable'] ?? 'UNKNOWN',
            'mergeState' => $pullRequest['mergeState'] ?? 'UNKNOWN',
            'review' => $pullRequest['review'] ?? '',
            'branch' => $pullRequest['branch'] ?? '',
            'repo' => $pullRequest['repo'] ?? '',
            'title' => $pullRequest['title'] ?? '',
        ];
    }

    /**
     * @param  WorkItem[]           $items
     * @param  array<string,string> $repoToHandle
     * @return void
     */
    public function resolve(array $items, array $repoToHandle): void
    {
        $found = $this->lookup($items, array_flip($repoToHandle));

        foreach ($items as $item) {
            $candidates = $this->candidatesFor($item, $found);
            if ($candidates === []) {
                continue;
            }

            foreach ($candidates as $number => $candidate) {
                $candidates[$number]['belongs'] = $this->belongsTo($candidate, $item);
            }

            $branch = $item->branch;

            // The row's own branch leads, so the branch and pull request columns agree.
            uasort($candidates, fn(array $a, array $b) => [
                $a['belongs'] ? 0 : 1,
                $a['branch'] === $branch ? 0 : 1,
                $this->rank($a),
                -$a['number'],
            ] <=> [
                $b['belongs'] ? 0 : 1,
                $b['branch'] === $branch ? 0 : 1,
                $this->rank($b),
                -$b['number'],
            ]);

            $item->prs = array_values($candidates);
            $item->pr = $item->prs[0];

            // The dev panel can name a pull request for a ticket we found no branch for.
            if ($item->branch === null && $item->pr['branch'] !== '') {
                $item->branch = $item->pr['branch'];
            }
        }
    }

    /**
     * Ask GitHub about every branch a row cares about, in one query.
     *
     * @param  WorkItem[]           $items
     * @param  array<string,string> $handleToRepo
     * @return array<string,array>
     */
    private function lookup(array $items, array $handleToRepo): array
    {
        $pairs = [];

        foreach ($items as $item) {
            $item->repo ??= $handleToRepo[$item->staging['handle'] ?? ''] ?? null;

            if ($item->pr === null && $item->branch !== null && $item->repo !== null) {
                $pairs[$item->repo . '#' . $item->branch] = ['repo' => $item->repo, 'branch' => $item->branch];
            }

            // Only open pull requests need GitHub: Jira already knows a merged one's
            // number, title and state, and a merged row carries no flags to work out.
            foreach ($item->linkedPullRequests as $linked) {
                if ($linked['state'] === 'open' && $linked['repo'] !== '' && $linked['branch'] !== '') {
                    $pairs[$linked['repo'] . '#' . $linked['branch']] = [
                        'repo' => $linked['repo'],
                        'branch' => $linked['branch'],
                    ];
                }
            }
        }

        return $pairs === [] ? [] : $this->github->pullRequestsForBranches(array_values($pairs));
    }

    /**
     * @param  WorkItem            $item
     * @param  array<string,array> $found
     * @return array<int,array>
     */
    private function candidatesFor(WorkItem $item, array $found): array
    {
        $candidates = [];

        if ($item->pr !== null) {
            $candidates[$item->pr['number']] = $item->pr;
        }

        $own = $found[$item->repo . '#' . $item->branch] ?? null;
        if ($own !== null) {
            $candidates[$own['number']] = self::summarise($own);
            $item->summary ??= $own['title'];
        }

        foreach ($item->linkedPullRequests as $linked) {
            // Fall back to what the dev panel told us when GitHub had nothing.
            $resolved = $found[$linked['repo'] . '#' . $linked['branch']] ?? $linked;
            $candidates[$linked['number']] ??= self::summarise($resolved);
        }

        return $candidates;
    }

    /**
     * Whether a pull request is this row's own work.
     *
     * Jira links a pull request to every ticket its description mentions, so a PR that
     * merely cites another ticket as the cause arrives on that ticket's row too. A PR
     * naming a different key in its branch or title belongs to that key, not this row.
     *
     * @param  array    $pullRequest
     * @param  WorkItem $item
     * @return bool
     */
    private function belongsTo(array $pullRequest, WorkItem $item): bool
    {
        $key = $item->ticketKey();
        if ($key === null) {
            return true;
        }

        $text = $pullRequest['branch'] . ' ' . $pullRequest['title'];
        if (Key::mentioned($text, $key)) {
            return true;
        }

        // No key of its own, so nothing contradicts this row's claim to it.
        return Key::allFromBranch($text) === [];
    }

    /**
     * Open pull requests matter most, then merged, then closed.
     *
     * @param  array $pullRequest
     * @return int
     */
    private function rank(array $pullRequest): int
    {
        return match ($pullRequest['state']) {
            'open' => 0,
            'merged' => 1,
            default => 2,
        };
    }
}
