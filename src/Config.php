<?php

namespace D3R\Tickets;

/**
 * Reads the ini config from ~/.config/d3r-tickets/config.ini.
 */
class Config
{
    private const string DEFAULT_PATH = '~/.config/d3r-tickets/config.ini';

    private array $values = [];

    private array $defaults = [
        'jira' => [
            'host' => 'd3rjira.atlassian.net',
            'email' => '',
            'token' => '',
        ],
        'github' => [
            'org' => 'D3R',
        ],
        'stoker' => [
            'ssh_host' => 'stack',
            'clients_path' => '',
            // How long to trust the cached deployment list. 0 disables caching.
            'cache_seconds' => '120',
        ],
        'display' => [
            'since_days' => '90',
            // Statuses --active hides. Comma separated, add Closed or Done to taste.
            'inactive' => 'Live',
        ],
    ];

    public function __construct(private ?string $path = null)
    {
        $this->path = $this->expand($path ?? self::DEFAULT_PATH);

        if (is_readable($this->path)) {
            $this->values = parse_ini_file($this->path, true, INI_SCANNER_TYPED) ?: [];
        }
    }

    /**
     * Fetch a config value, falling back to the built in default.
     *
     * @param  string $section
     * @param  string $key
     * @return string
     */
    public function get(string $section, string $key): string
    {
        $value = $this->values[$section][$key] ?? $this->defaults[$section][$key] ?? '';

        return trim((string) $value);
    }

    /**
     * Statuses that --active treats as finished.
     *
     * @return string[]
     */
    public function inactiveStatuses(): array
    {
        $statuses = array_map('trim', explode(',', $this->get('display', 'inactive')));

        return array_values(array_filter($statuses));
    }

    /**
     * Whether enough Jira credentials are present to attempt a call.
     *
     * @return bool
     */
    public function hasJira(): bool
    {
        return $this->get('jira', 'email') !== '' && $this->get('jira', 'token') !== '';
    }

    /**
     * Where clients are checked out locally, guessed from the mount when unset.
     *
     * @return string
     */
    public function clientsPath(): string
    {
        $configured = $this->get('stoker', 'clients_path');
        if ($configured !== '') {
            return $this->expand($configured);
        }

        foreach (glob('/Volumes/*/clients') ?: [] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Expand a leading tilde to the home directory.
     *
     * @param  string $path
     * @return string
     */
    private function expand(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim(getenv('HOME') ?: '', '/') . substr($path, 1);
        }

        return $path;
    }
}
