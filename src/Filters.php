<?php

namespace D3R\Tickets;

/**
 * Filters, following stoker's grammar: a partial identifier plus short flags.
 */
class Filters
{
    public function __construct(
        private ?string $identifier = null,
        private ?string $project = null,
        private ?string $status = null,
        private ?string $machine = null,
        private bool $everyone = false,
        private bool $activeOnly = false,
        private array $inactiveStatuses = [],
        private bool $unapprovedOnly = false
    ) {
    }

    /**
     * Whether to keep only rows with an open pull request awaiting approval.
     *
     * @return bool
     */
    public function unapprovedOnly(): bool
    {
        return $this->unapprovedOnly;
    }

    /**
     * Whether to include work that is not yours.
     *
     * @return bool
     */
    public function everyone(): bool
    {
        return $this->everyone;
    }

    /**
     * Build from parsed command line options.
     *
     * @param  array       $options
     * @param  string|null $identifier
     * @return self
     */
    public static function fromOptions(array $options, ?string $identifier, array $inactiveStatuses = []): self
    {
        $value = static fn(string $short, string $long): ?string
            => isset($options[$short]) ? (string) $options[$short]
                : (isset($options[$long]) ? (string) $options[$long] : null);

        return new self(
            $identifier,
            $value('p', 'project'),
            $value('s', 'status'),
            $value('m', 'machine'),
            isset($options['a']) || isset($options['anyone']),
            isset($options['active']),
            $inactiveStatuses,
            isset($options['unapproved'])
        );
    }

    /**
     * The single ticket being asked for, when the identifier names one outright.
     *
     * @return string|null
     */
    public function ticketKey(): ?string
    {
        if ($this->identifier !== null && Key::isComplete($this->identifier)) {
            return strtoupper($this->identifier);
        }

        return null;
    }

    /**
     * Extra JQL clauses, so Jira does the narrowing where it can.
     *
     * @return string[]
     */
    public function jqlClauses(): array
    {
        $clauses = [];

        $key = $this->ticketKey();
        if ($key !== null) {
            $clauses[] = sprintf('key = %s', $key);
        }

        if ($this->project !== null) {
            $clauses[] = sprintf('project = %s', strtoupper($this->project));
        }

        // Let Jira drop the finished ones rather than fetching them to discard them,
        // which also spares a dev panel call per ticket.
        if ($this->activeOnly && $this->inactiveStatuses !== []) {
            $quoted = array_map(static fn(string $status) => '"' . $status . '"', $this->inactiveStatuses);
            $clauses[] = sprintf('status not in (%s)', implode(', ', $quoted));
        }

        return $clauses;
    }

    /**
     * Whether an assembled item survives the filters.
     *
     * @param  WorkItem $item
     * @return bool
     */
    public function matches(WorkItem $item): bool
    {
        if ($this->activeOnly && $this->isFinished($item)) {
            return false;
        }

        if ($this->project !== null && !$this->matchesProject($item)) {
            return false;
        }

        if ($this->status !== null && !$this->contains($item->group(), $this->status)) {
            return false;
        }

        if ($this->machine !== null && !$this->contains($item->staging['machine'] ?? '', $this->machine)) {
            return false;
        }

        if ($this->identifier !== null && !$this->matchesIdentifier($item)) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function active(): bool
    {
        return $this->identifier !== null
            || $this->project !== null
            || $this->status !== null
            || $this->machine !== null
            || $this->unapprovedOnly;
    }

    /**
     * A one line description of what was filtered on, for the header.
     *
     * @return string
     */
    public function describe(): string
    {
        $parts = [];

        foreach (
            [
                'matching' => $this->identifier,
                'project' => $this->project,
                'status' => $this->status,
                'machine' => $this->machine,
                'awaiting approval' => $this->unapprovedOnly ? 'yes' : null,
            ] as $label => $value
        ) {
            if ($value !== null) {
                $parts[] = $label . ' ' . $value;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Whether this row is done with.
     *
     * A ticket is judged on its status. A row with no ticket has no status to judge, so
     * its pull request stands in: merged or closed with nothing deployed is finished.
     *
     * @param  WorkItem $item
     * @return bool
     */
    private function isFinished(WorkItem $item): bool
    {
        if ($item->status === null) {
            return $item->staging === null
                && in_array($item->pr['state'] ?? '', ['merged', 'closed'], true);
        }

        foreach ($this->inactiveStatuses as $status) {
            if (strcasecmp($item->status, $status) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  WorkItem $item
     * @return bool
     */
    private function matchesProject(WorkItem $item): bool
    {
        $key = $item->ticketKey();

        return $key !== null && strcasecmp(Key::project($key), (string) $this->project) === 0;
    }

    /**
     * The identifier matches a ticket key, a summary, a branch or a repo.
     *
     * @param  WorkItem $item
     * @return bool
     */
    private function matchesIdentifier(WorkItem $item): bool
    {
        foreach ([$item->ticketKey(), $item->summary, $item->branch, $item->repo] as $field) {
            if ($field !== null && $this->contains($field, $this->identifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string $haystack
     * @param  string $needle
     * @return bool
     */
    private function contains(string $haystack, string $needle): bool
    {
        return stripos($haystack, $needle) !== false;
    }
}
