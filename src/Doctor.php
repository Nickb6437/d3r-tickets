<?php

namespace D3R\Tickets;

/**
 * Checks the four things this tool needs before it can tell you anything useful.
 *
 * Every source degrades quietly by design, so a missing gh login or a halted stack box
 * shows up as an empty column rather than a reason. This says which one is at fault.
 */
final class Doctor
{
    /** @var array<int,array{name:string,ok:bool,detail:string,fix:string}> */
    private array $checks = [];

    public function __construct(private Config $config, private Ansi $ansi)
    {
    }

    /**
     * @return string
     */
    public function report(): string
    {
        $this->config();
        $this->jira();
        $this->github();
        $this->stoker();
        $this->clients();

        $output = "\n";
        foreach ($this->checks as $check) {
            $output .= sprintf(
                "%s %s %s\n",
                $check['ok'] ? $this->ansi->colour('ok', Ansi::GREEN) : $this->ansi->colour('!!', Ansi::RED),
                str_pad($check['name'], 10),
                $check['detail']
            );

            if (!$check['ok'] && $check['fix'] !== '') {
                $output .= sprintf("   %s %s\n", str_repeat(' ', 10), $this->ansi->dim($check['fix']));
            }
        }

        return $output . "\n";
    }

    /**
     * @return bool
     */
    public function healthy(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return void
     */
    private function config(): void
    {
        $path = $this->config->path();

        if (!is_readable($path)) {
            $this->add('config', false, 'No config at ' . $path, 'Copy config.ini.dist there and add your Jira token.');
            return;
        }

        $mode = substr(sprintf('%o', fileperms($path)), -3);
        $loose = $mode !== '600';

        $this->add(
            'config',
            !$loose,
            sprintf('%s (mode %s)', $path, $mode),
            $loose ? 'It holds an API token: chmod 600 ' . $path : ''
        );
    }

    /**
     * @return void
     */
    private function jira(): void
    {
        if (!$this->config->hasJira()) {
            $this->add('jira', false, 'No email or token configured', 'Create a token at id.atlassian.com and quote it in the config.');
            return;
        }

        $host = $this->config->get('jira', 'host');
        $authenticated = (new Jira($this->config))->me() !== '';

        $this->add(
            'jira',
            $authenticated,
            sprintf('%s, %s', $host, $authenticated ? 'authenticated' : 'rejected the credentials'),
            'Check the email and token, and that the token has not expired.'
        );
    }

    /**
     * @return void
     */
    private function github(): void
    {
        $status = Process::start('gh auth status');
        $output = $status->output();

        if ($status->failed()) {
            $this->add('github', false, 'gh is not logged in or not installed', 'Run: gh auth login');
            return;
        }

        preg_match('/account (\S+)/', $output, $account);
        $scopes = str_contains($output, "'repo'");

        $this->add(
            'github',
            $scopes,
            sprintf('%s%s', $account[1] ?? 'logged in', $scopes ? ', repo scope' : ', missing repo scope'),
            'Run: gh auth refresh -s repo'
        );
    }

    /**
     * @return void
     */
    private function stoker(): void
    {
        $host = $this->config->get('stoker', 'ssh_host');
        $probe = Process::start(sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=8 %s %s',
            escapeshellarg($host),
            escapeshellarg('command -v stoker')
        ));

        $output = trim($probe->output());

        if ($probe->failed()) {
            $this->add('stoker', false, sprintf('cannot ssh to "%s"', $host), 'Is the stack box up? Try: vagrant up, then ssh ' . $host);
            return;
        }

        $this->add(
            'stoker',
            $output !== '',
            $output !== '' ? sprintf('%s, stoker at %s', $host, $output) : sprintf('%s reachable but stoker is missing', $host),
            'stoker should be on the box PATH at /usr/local/bin/stoker.'
        );
    }

    /**
     * @return void
     */
    private function clients(): void
    {
        $path = $this->config->clientsPath();

        if ($path === '' || !is_dir($path)) {
            $this->add('clients', false, 'No client checkouts found', 'Set stoker.clients_path in the config to where your clients live.');
            return;
        }

        $count = count((new Stoker($this->config))->repoToHandle());

        $this->add(
            'clients',
            $count > 0,
            sprintf('%s (%d repo%s mapped)', $path, $count, $count === 1 ? '' : 's'),
            'Handles come from stoker, repos from each checkout\'s git remote, so both must work.'
        );
    }

    /**
     * @param  string $name
     * @param  bool   $ok
     * @param  string $detail
     * @param  string $fix
     * @return void
     */
    private function add(string $name, bool $ok, string $detail, string $fix = ''): void
    {
        $this->checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
    }
}
