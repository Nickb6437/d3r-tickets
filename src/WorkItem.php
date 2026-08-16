<?php

namespace D3R\Tickets;

/**
 * One piece of work in flight, joined across Jira, GitHub and stoker.
 *
 * The branch is the join key: a Jira ticket is one possible label on a branch,
 * not the thing itself, so vendor and core work without a ticket still lands here.
 */
class WorkItem
{
    public const string NO_TICKET = 'No ticket';

    public ?string $key = null;

    public ?string $summary = null;

    public ?string $status = null;

    public ?string $branch = null;

    public ?string $repo = null;

    /** The pull request that best represents this row. */
    public ?array $pr = null;

    /** Every pull request on the ticket, primary first: yours, the ticket branch's, closed attempts. */
    public array $prs = [];

    /** Pull requests the Jira dev panel links to this ticket, before GitHub fills in their state. */
    public array $linkedPullRequests = [];

    /** @var array{alias:string,handle:string,machine:string,url:string,updated:string}|null */
    public ?array $staging = null;

    public ?string $updated = null;

    /** Someone else's ticket, shown because your branch or a staging box belongs to it. */
    public bool $foreign = false;

    /** Built from a Jira ticket rather than inferred from a branch. */
    public bool $fromTicket = false;

    public ?string $assigneeName = null;

    /**
     * The group heading this item sits under.
     *
     * @return string
     */
    public function group(): string
    {
        return $this->key === null ? self::NO_TICKET : ($this->status ?? 'Unknown');
    }

    /**
     * Sort key within a group, most recently touched first.
     *
     * @return string
     */
    public function sortKey(): string
    {
        return $this->updated ?? '';
    }

    /**
     * The ticket key, read off the branch when there is no ticket.
     *
     * @return string|null
     */
    public function ticketKey(): ?string
    {
        return $this->key ?? ($this->branch === null ? null : Key::fromBranch($this->branch));
    }

    /**
     * Short repo name without the owner prefix.
     *
     * @return string
     */
    public function repoName(): string
    {
        return $this->repo === null ? '' : basename($this->repo);
    }
}
