<?php

namespace D3R\Tickets;

/**
 * A spinner on stderr, so a ten second wait looks like work rather than a hang.
 *
 * Static because it is called from inside the blocking read loops in Process and Jira,
 * which would otherwise need a progress handle threaded through four constructors for
 * what is purely a terminal concern. Stdout stays clean, so piping is unaffected.
 */
final class Progress
{
    private const array FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** Redrawing faster than this just burns cycles. */
    private const float INTERVAL = 0.08;

    private static bool $enabled = false;

    private static string $label = '';

    private static int $frame = 0;

    private static float $drawnAt = 0.0;

    /**
     * @param  bool $enabled
     * @return void
     */
    public static function enable(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    /**
     * Name what is happening now.
     *
     * @param  string $label
     * @return void
     */
    public static function step(string $label): void
    {
        self::$label = $label;
        self::$drawnAt = 0.0;
        self::tick();
    }

    /**
     * Advance the spinner, called from whatever loop is currently waiting.
     *
     * @return void
     */
    public static function tick(): void
    {
        if (!self::$enabled || microtime(true) - self::$drawnAt < self::INTERVAL) {
            return;
        }

        self::$drawnAt = microtime(true);
        self::$frame = (self::$frame + 1) % count(self::FRAMES);

        fwrite(STDERR, sprintf("\r\e[K%s %s", self::FRAMES[self::$frame], self::$label));
    }

    /**
     * Wipe the line before anything else is printed.
     *
     * @return void
     */
    public static function clear(): void
    {
        if (self::$enabled) {
            fwrite(STDERR, "\r\e[K");
        }
    }
}
