<?php

namespace D3R\Tickets;

/**
 * Stoker over the vagrant tunnel: staging deployments and the local client list.
 *
 * One ssh round trip fetches both tables. Staging hostnames follow
 * <alias>-<handle>-<machine>.d3r.com, with the scheme taken from the HTTPS column.
 */
class Stoker
{
    private const string MARKER = '===LOCAL===';

    /** @var string[] */
    private array $warnings = [];

    /** @var array<int,array<string,string>>|null */
    private ?array $staging = null;

    /** @var string[]|null */
    private ?array $localHandles = null;

    private ?Process $process = null;

    private ?string $cached = null;

    public function __construct(private Config $config, private bool $useCache = true)
    {
    }

    /**
     * Start the ssh call so it runs while the other sources are read.
     *
     * Deployments change through the day but not by the second, so a short lived cache
     * removes the slowest call from most runs.
     *
     * @return void
     */
    public function prime(): void
    {
        if ($this->process !== null || $this->cached !== null) {
            return;
        }

        $this->cached = $this->useCache ? $this->readCache() : null;

        if ($this->cached === null) {
            $this->process = Process::start($this->command());
        }
    }

    /**
     * Staging deployments, keyed by branch name.
     *
     * @return array<string,array<int,array{alias:string,handle:string,machine:string,url:string,updated:string}>>
     */
    public function stagingByBranch(): array
    {
        $this->load();

        $byBranch = [];
        foreach ($this->staging ?? [] as $row) {
            $branch = $row['Branch'] ?? '';
            if ($branch === '') {
                continue;
            }

            $byBranch[$branch][] = [
                'alias' => $row['Alias'] ?? '',
                'handle' => $row['Handle'] ?? '',
                'machine' => $row['Machine'] ?? '',
                'url' => $this->url($row),
                'updated' => $row['Updated'] ?? '',
            ];
        }

        return $byBranch;
    }

    /**
     * Map of GitHub repo (owner/name) to stoker handle, read from local checkouts.
     *
     * This is what catches thesleeproom mapping to D3R/loaf.
     *
     * @return array<string,string>
     */
    public function repoToHandle(): array
    {
        $this->load();

        $root = $this->config->clientsPath();
        if ($root === '' || !is_dir($root)) {
            return [];
        }

        $map = [];
        foreach ($this->localHandles ?? [] as $handle) {
            $releases = glob($root . '/' . $handle . '/*/releases/*', GLOB_ONLYDIR) ?: [];
            rsort($releases);

            foreach ($releases as $release) {
                if (!is_dir($release . '/.git')) {
                    continue;
                }

                $remote = trim((string) shell_exec(
                    sprintf('git -C %s remote get-url origin 2>/dev/null', escapeshellarg($release))
                ));

                $repo = $this->repoFromRemote($remote);
                if ($repo !== null) {
                    $map[$repo] = $handle;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Fetch both stoker tables in a single ssh call.
     *
     * @return void
     */
    private function load(): void
    {
        if ($this->staging !== null) {
            return;
        }

        $this->staging = [];
        $this->localHandles = [];

        $this->prime();

        if ($this->cached === null) {
            if ($this->process->failed()) {
                $this->warnings[] = 'stoker unreachable, so staging links are missing. Is the stack box up?';
                return;
            }

            $this->cached = $this->process->output();
            $this->writeCache($this->cached);
        }

        $output = explode("\n", $this->cached);

        $split = array_search(self::MARKER, array_map('trim', $output), true);
        $stagingLines = $split === false ? $output : array_slice($output, 0, $split);
        $localLines = $split === false ? [] : array_slice($output, $split + 1);

        $this->staging = $this->parseTable($stagingLines);

        foreach ($this->parseTable($localLines) as $row) {
            $handle = $row['Handle'] ?? '';
            if ($handle !== '' && !in_array($handle, $this->localHandles, true)) {
                $this->localHandles[] = $handle;
            }
        }
    }

    /**
     * The cached stoker output, if it is still young enough to trust.
     *
     * @return string|null
     */
    private function readCache(): ?string
    {
        $path = $this->cachePath();
        $seconds = (int) $this->config->get('stoker', 'cache_seconds');

        if ($seconds <= 0 || !is_readable($path) || filemtime($path) < time() - $seconds) {
            return null;
        }

        return file_get_contents($path) ?: null;
    }

    /**
     * @param  string $output
     * @return void
     */
    private function writeCache(string $output): void
    {
        if ((int) $this->config->get('stoker', 'cache_seconds') <= 0) {
            return;
        }

        $path = $this->cachePath();

        if (is_dir(dirname($path)) || mkdir(dirname($path), 0700, true)) {
            file_put_contents($path, $output);
        }
    }

    /**
     * @return string
     */
    private function cachePath(): string
    {
        return rtrim(getenv('HOME') ?: '', '/') . '/.cache/d3r-tickets/deployments.txt';
    }

    /**
     * Both tables in a single ssh round trip.
     *
     * @return string
     */
    private function command(): string
    {
        $remote = sprintf(
            'stoker deployment:ls -e staging --no-ansi; echo %s; stoker deployment:ls --local --no-ansi',
            escapeshellarg(self::MARKER)
        );

        return sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=8 %s %s',
            escapeshellarg($this->config->get('stoker', 'ssh_host')),
            escapeshellarg($remote)
        );
    }

    /**
     * Parse a stoker ascii table into rows keyed by column heading.
     *
     * @param  string[] $lines
     * @return array<int,array<string,string>>
     */
    private function parseTable(array $lines): array
    {
        $headings = [];
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map('trim', array_slice(explode('|', $line), 1, -1));

            if ($headings === []) {
                $headings = $cells;
                continue;
            }

            if (count($cells) === count($headings)) {
                $rows[] = array_combine($headings, $cells);
            }
        }

        return $rows;
    }

    /**
     * Compose the staging URL for a deployment row.
     *
     * @param  array<string,string> $row
     * @return string
     */
    private function url(array $row): string
    {
        $scheme = str_starts_with(strtolower($row['HTTPS'] ?? ''), 'on') ? 'https' : 'http';

        return sprintf(
            '%s://%s-%s-%s.d3r.com',
            $scheme,
            $row['Alias'] ?? '',
            $row['Handle'] ?? '',
            $row['Machine'] ?? ''
        );
    }

    /**
     * Normalise a git remote to owner/name.
     *
     * @param  string $remote
     * @return string|null
     */
    private function repoFromRemote(string $remote): ?string
    {
        if ($remote === '') {
            return null;
        }

        $remote = preg_replace('#\.git$#', '', $remote) ?? $remote;

        if (preg_match('#[:/]([^/:]+/[^/]+)$#', $remote, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
