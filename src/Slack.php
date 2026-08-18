<?php

namespace D3R\Tickets;

/**
 * A plain list to paste into Slack when asking for reviews.
 *
 * Deliberately unstyled: no colour, no OSC 8 hyperlinks, no box drawing. Slack turns a
 * bare URL into a link by itself, whereas its `<url|label>` form only works through the
 * API, not text typed or pasted into the composer.
 */
final class Slack
{
    /** @var string[] */
    private array $notes = [];

    /**
     * @param  WorkItem[] $items
     * @return string
     */
    public function render(array $items): string
    {
        $lines = [];
        $missing = 0;
        $blocked = [];

        foreach ($items as $item) {
            $pullRequest = $this->reviewable($item);

            if ($pullRequest === null) {
                $missing++;
                continue;
            }

            $label = trim(sprintf('%s %s', $item->key ?? $item->branch, $this->summary($item)));
            $lines[] = sprintf('• %s: %s', $label, $pullRequest['url']);

            if ($pullRequest['mergeable'] === 'CONFLICTING') {
                $blocked[] = $item->key ?? $item->branch;
            }
        }

        if ($missing > 0) {
            $this->notes[] = sprintf(
                '%d row%s left out, no open pull request to review.',
                $missing,
                $missing === 1 ? '' : 's'
            );
        }

        if ($blocked !== []) {
            $this->notes[] = sprintf('Conflicting, so not really reviewable yet: %s.', implode(', ', $blocked));
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Anything worth telling you, but kept out of the text you are about to paste.
     *
     * @return string[]
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * The summary, tidied for pasting.
     *
     * Jira summaries arrive with stray double spaces, and some already start with their
     * own key, which would otherwise print twice.
     *
     * @param  WorkItem $item
     * @return string
     */
    private function summary(WorkItem $item): string
    {
        $summary = trim(preg_replace('/\s+/', ' ', $item->summary ?? '') ?? '');

        if ($item->key === null) {
            return $summary;
        }

        return trim(preg_replace('/^' . preg_quote($item->key, '/') . '\s*:?\s*/i', '', $summary) ?? $summary);
    }

    /**
     * The open pull request on a row, which is the only kind worth asking about.
     *
     * @param  WorkItem $item
     * @return array|null
     */
    private function reviewable(WorkItem $item): ?array
    {
        foreach ($item->prs as $pullRequest) {
            if ($pullRequest['state'] === 'open' && !$pullRequest['draft']) {
                return $pullRequest;
            }
        }

        return null;
    }
}
