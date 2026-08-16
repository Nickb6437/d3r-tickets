<?php

namespace D3R\Tickets;

/**
 * A shell command running in the background.
 *
 * gh and ssh are the two slowest sources and neither depends on the other, so both
 * are started up front and read once Jira has been dealt with.
 */
final class Process
{
    /** @var resource|false */
    private $handle;

    /** @var array<int,resource> */
    private array $pipes = [];

    private ?string $output = null;

    private int $status = 0;

    private function __construct(string $command)
    {
        $this->handle = proc_open($command . ' 2>&1', [1 => ['pipe', 'w']], $this->pipes);
    }

    /**
     * @param  string $command
     * @return self
     */
    public static function start(string $command): self
    {
        return new self($command);
    }

    /**
     * Everything the command wrote, blocking until it finishes.
     *
     * @return string
     */
    public function output(): string
    {
        if ($this->output !== null) {
            return $this->output;
        }

        if ($this->handle === false) {
            $this->status = 1;
            return $this->output = '';
        }

        // Read in slices rather than blocking outright, so the spinner keeps moving.
        $this->output = '';
        while (!feof($this->pipes[1])) {
            $read = [$this->pipes[1]];
            $write = $except = null;

            if (stream_select($read, $write, $except, 0, 100000) > 0) {
                $chunk = fread($this->pipes[1], 8192);
                if ($chunk === false) {
                    break;
                }
                $this->output .= $chunk;
            }

            Progress::tick();
        }

        fclose($this->pipes[1]);
        $this->status = proc_close($this->handle);

        return $this->output;
    }

    /**
     * @return bool
     */
    public function failed(): bool
    {
        $this->output();

        return $this->status !== 0;
    }
}
