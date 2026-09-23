<?php

namespace D3R\Tickets;

/**
 * Assembles the three sources into one list of work items.
 *
 * Branch is the join key. Tickets contribute a label and a status, pull requests the
 * branch and its state, stoker where it is deployed. Rows then collapse per ticket,
 * because a ticket branch, a personal branch off it and a staging box are three views
 * of the same work.
 */
class Board
{
    public function __construct(
        private Jira $jira,
        private GitHub $github,
        private Stoker $stoker,
        private Config $config
    ) {
    }

    /**
     * @param  int          $sinceDays
     * @param  Filters|null $filters
     * @return WorkItem[]
     */
    public function build(int $sinceDays, ?Filters $filters = null): array
    {
        $filters ??= new Filters();

        // gh and ssh are the slow ones and neither needs the other, so start both now.
        $this->github->prime($this->config->get('github', 'org'), $sinceDays);
        $this->stoker->prime();

        Progress::step('reading staging deployments');
        $staging = $this->stoker->stagingByBranch();
        $repoToHandle = $this->stoker->repoToHandle();

        Progress::step('reading your pull requests');
        $pullRequests = $this->github->authoredPullRequests($this->config->get('github', 'org'), $sinceDays);

        Progress::step('reading tickets');

        // A named ticket is fetched outright: no time bound, and not only yours.
        $named = $filters->ticketKey() !== null;
        $jira = $this->config->hasJira()
            ? $this->jira->fetch(
                $named ? 0 : $sinceDays,
                $filters->jqlClauses(),
                !$named,
                $this->candidateKeys($pullRequests, $filters->everyone() ? $staging : [])
            )
            : ['issues' => [], 'tickets' => [], 'accountId' => ''];

        Progress::step('reading linked branches');
        $development = $this->jira->development(array_merge(
            array_column($jira['issues'], 'id'),
            array_column($jira['tickets'], 'id')
        ));

        $items = $this->ticketRows($jira['issues'], $development, $pullRequests, $staging);
        $items = array_merge($items, $this->pullRequestRows($pullRequests, $items));

        $matcher = new StagingMatcher($staging, $repoToHandle);
        $matcher->attachExact($items);

        if ($filters->everyone()) {
            $items = array_merge($items, $matcher->unclaimed());
        }

        $matcher->attachByKey($items);

        $this->label($items, $jira['tickets'], $jira['accountId'], $development);
        $items = $this->collapseByTicket($items);
        $items = array_values(array_filter($items, static fn(WorkItem $item) => $filters->matches($item)));

        Progress::step('reading pull request states');
        (new PullRequests($this->github))->resolve($items, $repoToHandle);

        // Review state only exists once GitHub has been asked, so this pass waits.
        if ($filters->unapprovedOnly()) {
            $items = array_values(array_filter($items, static fn(WorkItem $item) => $item->awaitingApproval()));
        }

        usort($items, static fn(WorkItem $a, WorkItem $b) => strcmp($b->sortKey(), $a->sortKey()));

        Progress::clear();

        return $items;
    }

    /**
     * Ticket keys our branches name, so Jira can be asked about them in the same trip.
     *
     * @param  array<string,array> $pullRequests
     * @param  array<string,array> $staging
     * @return string[]
     */
    private function candidateKeys(array $pullRequests, array $staging): array
    {
        $sources = array_merge(
            array_keys($pullRequests),
            array_keys($staging),
            array_column($pullRequests, 'title')
        );

        return array_merge(...array_map(Key::allFromBranch(...), $sources));
    }

    /**
     * @param  array<int,array>    $issues
     * @param  array<string,array> $development
     * @param  array<string,array> $pullRequests
     * @param  array<string,array> $staging
     * @return WorkItem[]
     */
    private function ticketRows(array $issues, array $development, array $pullRequests, array $staging): array
    {
        $items = [];

        foreach ($issues as $issue) {
            $item = new WorkItem();
            $item->fromTicket = true;
            $item->key = $issue['key'];
            $item->summary = $issue['summary'];
            $item->status = $issue['status'];
            $item->updated = $issue['updated'];
            $item->linkedPullRequests = $development[$issue['id']]['pullRequests'] ?? [];
            $item->branch = $this->branchFor($issue, $development, $pullRequests, $staging);

            if ($item->branch !== null && isset($pullRequests[$item->branch])) {
                $item->pr = PullRequests::summarise($pullRequests[$item->branch]);
                $item->repo = $pullRequests[$item->branch]['repo'];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Pull requests no ticket claimed: vendor and core work, spikes, other people's tickets.
     *
     * @param  array<string,array> $pullRequests
     * @param  WorkItem[]          $claimed
     * @return WorkItem[]
     */
    private function pullRequestRows(array $pullRequests, array $claimed): array
    {
        $branches = array_filter(array_map(static fn(WorkItem $item) => $item->branch, $claimed));
        $items = [];

        foreach ($pullRequests as $branch => $pullRequest) {
            if (in_array($branch, $branches, true)) {
                continue;
            }

            $item = new WorkItem();
            $item->summary = $pullRequest['title'];
            $item->branch = $branch;
            $item->repo = $pullRequest['repo'];
            $item->pr = PullRequests::summarise($pullRequest);
            $item->updated = $pullRequest['updated'];

            $items[] = $item;
        }

        return $items;
    }

    /**
     * The branch a ticket is on: the dev panel first, then the ticket key.
     *
     * @param  array               $issue
     * @param  array<string,array> $development
     * @param  array<string,array> $pullRequests
     * @param  array<string,array> $staging
     * @return string|null
     */
    private function branchFor(array $issue, array $development, array $pullRequests, array $staging): ?string
    {
        $branches = $development[$issue['id']]['branches'] ?? [];

        if ($branches === []) {
            $key = $issue['key'];

            foreach (array_merge(array_keys($pullRequests), array_keys($staging)) as $branch) {
                if (Key::mentioned($branch, $key)) {
                    $branches[] = $branch;
                }
            }

            foreach ($pullRequests as $branch => $pullRequest) {
                if (Key::mentioned($pullRequest['title'], $key)) {
                    $branches[] = $branch;
                }
            }
        }

        if ($branches === []) {
            return null;
        }

        foreach ($branches as $branch) {
            if (isset($pullRequests[$branch])) {
                return $branch;
            }
        }

        return reset($branches);
    }

    /**
     * Label rows whose branch names a ticket that was not in your own list.
     *
     * @param  WorkItem[]          $items
     * @param  array<string,array> $tickets
     * @param  string              $accountId
     * @param  array<string,array> $development
     * @return void
     */
    private function label(array $items, array $tickets, string $accountId, array $development): void
    {
        foreach ($items as $item) {
            if ($item->key !== null || $item->branch === null) {
                continue;
            }

            // The first key-shaped token Jira actually recognises is the real one.
            $ticket = null;
            foreach ($item->candidateKeys() as $candidate) {
                $ticket = $tickets[$candidate] ?? null;
                if ($ticket !== null) {
                    break;
                }
            }

            if ($ticket === null) {
                continue;
            }

            $item->key = $ticket['key'];
            $item->status = $ticket['status'];
            $item->summary ??= $ticket['summary'];
            $item->assigneeName = $ticket['assigneeName'];
            $item->foreign = $ticket['assignee'] !== $accountId;
            $item->linkedPullRequests = $development[$ticket['id']]['pullRequests'] ?? [];
        }
    }

    /**
     * One row per ticket, folding duplicates into whichever row carries the most.
     *
     * @param  WorkItem[] $items
     * @return WorkItem[]
     */
    private function collapseByTicket(array $items): array
    {
        $result = [];
        $positionOf = [];

        foreach ($items as $item) {
            $key = $item->key;

            if ($key === null) {
                $result[] = $item;
                continue;
            }

            if (!isset($positionOf[$key])) {
                $positionOf[$key] = count($result);
                $result[] = $item;
                continue;
            }

            $position = $positionOf[$key];
            $existing = $result[$position];

            [$winner, $loser] = $this->weight($item) > $this->weight($existing)
                ? [$item, $existing]
                : [$existing, $item];

            $winner->staging ??= $loser->staging;
            $winner->linkedPullRequests = array_merge(
                $winner->linkedPullRequests,
                $loser->linkedPullRequests,
                $loser->pr === null ? [] : [$loser->pr + ['repo' => $loser->repo ?? '']]
            );

            $result[$position] = $winner;
        }

        return $result;
    }

    /**
     * How much a row carries, used to pick which duplicate survives.
     *
     * @param  WorkItem $item
     * @return int
     */
    private function weight(WorkItem $item): int
    {
        return ($item->fromTicket ? 2 : 0) + ($item->staging !== null ? 1 : 0);
    }
}
