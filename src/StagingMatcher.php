<?php

namespace D3R\Tickets;

/**
 * Works out which staging box belongs to which row.
 *
 * Matching runs in passes so a deployment is only ever credited once: the branch it
 * actually runs first, then the ticket key, which is the only thing tying a personal
 * branch to the ticket branch that gets deployed.
 */
final class StagingMatcher
{
    private const array GENERIC_BRANCHES = ['master', 'main', 'trunk', 'develop'];

    /** @var array<string,bool> */
    private array $used = [];

    /**
     * @param array<string,array> $staging      Deployments keyed by branch.
     * @param array<string,string> $repoToHandle
     */
    public function __construct(private array $staging, private array $repoToHandle)
    {
    }

    /**
     * @param  WorkItem[] $items
     * @return void
     */
    public function attachExact(array $items): void
    {
        foreach ($items as $item) {
            if ($item->staging !== null || !$this->matchable($item)) {
                continue;
            }

            foreach ($this->staging[$item->branch] ?? [] as $deployment) {
                $this->claim($item, $deployment);
                break;
            }
        }
    }

    /**
     * @param  WorkItem[] $items
     * @return void
     */
    public function attachByKey(array $items): void
    {
        foreach ($items as $item) {
            if ($item->staging !== null || !$this->matchable($item)) {
                continue;
            }

            $key = $item->ticketKey();
            if ($key === null) {
                continue;
            }

            $handle = $this->handleFor($item);

            foreach ($this->staging as $branch => $deployments) {
                if (!Key::mentioned($branch, $key)) {
                    continue;
                }

                foreach ($deployments as $deployment) {
                    if (isset($this->used[$this->id($deployment)])) {
                        continue;
                    }

                    if ($handle === null || $deployment['handle'] === $handle) {
                        $this->claim($item, $deployment);
                        continue 3;
                    }
                }
            }
        }
    }

    /**
     * Rows for boxes nobody claimed, so a machine listing shows everything on it.
     *
     * Generic branches are listed here even though they never match a row: a box sitting
     * on trunk is still a staging site you may want the link for.
     *
     * @return WorkItem[]
     */
    public function unclaimed(): array
    {
        $handleToRepo = array_flip($this->repoToHandle);
        $items = [];

        foreach ($this->staging as $branch => $deployments) {
            foreach ($deployments as $deployment) {
                if (isset($this->used[$this->id($deployment)])) {
                    continue;
                }

                $item = new WorkItem();
                $item->branch = $branch;
                $item->repo = $handleToRepo[$deployment['handle']] ?? null;
                $item->foreign = true;
                // "2026-08-14 15:17:59 by nickb" sorts fine on its date alone.
                $item->updated = substr($deployment['updated'], 0, 19);

                $this->claim($item, $deployment);
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  WorkItem $item
     * @param  array    $deployment
     * @return void
     */
    private function claim(WorkItem $item, array $deployment): void
    {
        $item->staging = $deployment;
        $this->used[$this->id($deployment)] = true;
    }

    /**
     * @param  WorkItem $item
     * @return bool
     */
    private function matchable(WorkItem $item): bool
    {
        return $item->branch !== null && !$this->isGeneric($item->branch);
    }

    /**
     * @param  WorkItem $item
     * @return string|null
     */
    private function handleFor(WorkItem $item): ?string
    {
        return $item->repo === null ? null : ($this->repoToHandle[$item->repo] ?? null);
    }

    /**
     * @param  array $deployment
     * @return string
     */
    private function id(array $deployment): string
    {
        return $deployment['handle'] . '|' . $deployment['machine'] . '|' . $deployment['alias'];
    }

    /**
     * @param  string $branch
     * @return bool
     */
    private function isGeneric(string $branch): bool
    {
        return in_array(strtolower($branch), self::GENERIC_BRANCHES, true);
    }
}
