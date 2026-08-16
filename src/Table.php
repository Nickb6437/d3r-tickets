<?php

namespace D3R\Tickets;

/**
 * Renders work items as a grouped terminal table.
 */
class Table
{
    private const int KEY_WIDTH = 9;

    private const int PR_WIDTH = 28;

    private const int STAGING_WIDTH = 14;

    private int $width;

    /** The repo most rows belong to, left unlabelled so other repos stand out. */
    private string $primaryRepo = '';

    public function __construct(private Ansi $ansi, private string $jiraHost = '')
    {
        $this->width = $this->terminalWidth();
    }

    /**
     * @param  WorkItem[] $items
     * @return string
     */
    public function render(array $items, string $emptyMessage = 'Nothing assigned and nothing in flight.'): string
    {
        if ($items === []) {
            return $this->ansi->dim($emptyMessage . "\n");
        }

        $this->primaryRepo = $this->commonestRepo($items);

        $flexible = $this->width - (self::KEY_WIDTH + self::PR_WIDTH + self::STAGING_WIDTH + 4);
        $summaryWidth = max(20, (int) floor($flexible * 0.55));
        $branchWidth = max(16, $flexible - $summaryWidth);

        $output = '';
        foreach ($this->group($items) as $heading => $group) {
            $output .= sprintf(
                "\n%s %s\n",
                $this->ansi->bold(mb_strtoupper($heading)),
                $this->ansi->dim('(' . count($group) . ')')
            );

            foreach ($group as $item) {
                $output .= sprintf(
                    "%s %s %s %s %s\n",
                    $this->pad($this->key($item), self::KEY_WIDTH),
                    $this->pad($this->summary($item), $summaryWidth),
                    $this->pad($this->branch($item), $branchWidth),
                    $this->pad($this->pullRequest($item->pr), self::PR_WIDTH),
                    $this->staging($item)
                );

                foreach (array_slice($item->prs, 1) as $extra) {
                    $output .= sprintf(
                        "%s %s %s %s\n",
                        str_repeat(' ', self::KEY_WIDTH),
                        $this->pad($this->ansi->dim('└ ') . $this->ansi->link($extra['url'], $extra['title']), $summaryWidth),
                        $this->pad($this->ansi->dim($this->qualify($extra['branch'], $extra['repo'])), $branchWidth),
                        $this->pullRequest($extra)
                    );
                }
            }
        }

        return $output;
    }

    /**
     * Group items by status, most recently touched group first, no ticket last.
     *
     * @param  WorkItem[] $items
     * @return array<string,WorkItem[]>
     */
    private function group(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$item->group()][] = $item;
        }

        uksort($groups, function (string $a, string $b) use ($groups): int {
            if ($a === WorkItem::NO_TICKET) {
                return 1;
            }
            if ($b === WorkItem::NO_TICKET) {
                return -1;
            }

            return strcmp($groups[$b][0]->sortKey(), $groups[$a][0]->sortKey());
        });

        return $groups;
    }

    /**
     * The ticket key, marked with * when the ticket is not assigned to you.
     *
     * @param  WorkItem $item
     * @return string
     */
    private function key(WorkItem $item): string
    {
        if ($item->key === null) {
            return $this->ansi->dim('-');
        }

        $label = $item->foreign ? $item->key . $this->ansi->dim('*') : $item->key;

        if ($this->jiraHost === '') {
            return $label;
        }

        return $this->ansi->link(sprintf('https://%s/browse/%s', $this->jiraHost, $item->key), $label);
    }

    /**
     * The summary, linked to the pull request it describes.
     *
     * @param  WorkItem $item
     * @return string
     */
    private function summary(WorkItem $item): string
    {
        $summary = $item->summary ?? '';

        if ($item->pr === null || $summary === '') {
            return $summary;
        }

        return $this->ansi->link($item->pr['url'], $summary);
    }

    /**
     * @param  WorkItem $item
     * @return string
     */
    private function branch(WorkItem $item): string
    {
        if ($item->branch === null) {
            return $this->ansi->dim('-');
        }

        return $this->qualify($item->branch, (string) $item->repo);
    }

    /**
     * Name the repo unless it is the one most of the list belongs to.
     *
     * A package change often shares its branch name with the site consuming it, so the
     * repo is what tells the two apart.
     *
     * @param  string $branch
     * @param  string $repo
     * @return string
     */
    private function qualify(string $branch, string $repo): string
    {
        $name = $repo === '' ? '' : basename($repo);

        if ($name === '' || $name === $this->primaryRepo) {
            return $branch;
        }

        return $this->ansi->dim($name . ':') . $branch;
    }

    /**
     * @param  WorkItem[] $items
     * @return string
     */
    private function commonestRepo(array $items): string
    {
        $counts = array_count_values(array_filter(array_map(
            static fn(WorkItem $item) => $item->repoName(),
            $items
        )));

        if ($counts === []) {
            return '';
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  WorkItem $item
     * @return string
     */
    private function pullRequest(?array $pullRequest): string
    {
        if ($pullRequest === null) {
            return $this->ansi->dim('-');
        }

        $label = '#' . $pullRequest['number'];
        $state = $pullRequest['draft'] ? 'draft' : $pullRequest['state'];

        $colour = match ($state) {
            'open' => Ansi::GREEN,
            'merged' => Ansi::MAGENTA,
            'closed' => Ansi::RED,
            default => Ansi::GREY,
        };

        return $this->ansi->link($pullRequest['url'], $this->ansi->colour($label, $colour))
            . ' ' . $this->ansi->colour($state, $colour)
            . $this->flag($pullRequest, $state);
    }

    /**
     * What stands between an open pull request and merging.
     *
     * Blockers first, then the review. An approved pull request that is merely behind
     * is the one worth spotting, so both show rather than the blocker hiding the tick.
     *
     * @param  array  $pullRequest
     * @param  string $state
     * @return string
     */
    private function flag(array $pullRequest, string $state): string
    {
        if ($state === 'merged' || $state === 'closed') {
            return '';
        }

        $flags = [];

        if ($pullRequest['mergeable'] === 'CONFLICTING') {
            $flags[] = $this->ansi->colour('conflict', Ansi::RED);
        } elseif ($pullRequest['mergeState'] === 'BEHIND') {
            $flags[] = $this->ansi->colour('behind', Ansi::YELLOW);
        }

        $review = match ($pullRequest['review'] ?? '') {
            'APPROVED' => $this->ansi->colour('approved', Ansi::GREEN),
            'CHANGES_REQUESTED' => $this->ansi->colour('changes', Ansi::YELLOW),
            default => '',
        };

        if ($review !== '') {
            $flags[] = $review;
        }

        return $flags === [] ? '' : ' ' . implode(' ', $flags);
    }

    /**
     * @param  WorkItem $item
     * @return string
     */
    private function staging(WorkItem $item): string
    {
        if ($item->staging === null) {
            return $this->ansi->dim('-');
        }

        return $this->ansi->link($item->staging['url'], $this->ansi->colour($item->staging['alias'], Ansi::CYAN));
    }

    /**
     * Pad to a visible width, truncating with an ellipsis, ignoring escape codes.
     *
     * @param  string $text
     * @param  int    $width
     * @return string
     */
    private function pad(string $text, int $width): string
    {
        $visible = Ansi::strip($text);
        $length = mb_strlen($visible);

        if ($length > $width) {
            // Truncating styled text is not worth the complexity: restyle the plain form.
            return mb_substr($visible, 0, $width - 1) . '…';
        }

        return $text . str_repeat(' ', $width - $length);
    }







    /**
     * @return int
     */
    private function terminalWidth(): int
    {
        $columns = (int) (getenv('COLUMNS') ?: 0);
        if ($columns > 0) {
            return $columns;
        }

        $detected = (int) trim((string) shell_exec('tput cols 2>/dev/null'));

        return $detected > 40 ? $detected : 160;
    }
}
