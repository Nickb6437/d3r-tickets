<?php

namespace D3R\Tickets;

/**
 * Terminal styling, switched off in one place when output is piped.
 */
final class Ansi
{
    public const string RED = '31';

    public const string GREEN = '32';

    public const string YELLOW = '33';

    public const string MAGENTA = '35';

    public const string CYAN = '36';

    public const string GREY = '90';

    public function __construct(private bool $colour = true, private bool $links = true)
    {
    }

    /**
     * @param  string $text
     * @param  string $code
     * @return string
     */
    public function colour(string $text, string $code): string
    {
        return $this->colour ? "\e[" . $code . 'm' . $text . "\e[0m" : $text;
    }

    /**
     * @param  string $text
     * @return string
     */
    public function dim(string $text): string
    {
        return $this->colour($text, self::GREY);
    }

    /**
     * @param  string $text
     * @return string
     */
    public function bold(string $text): string
    {
        return $this->colour($text, '1;' . self::CYAN);
    }

    /**
     * An OSC 8 hyperlink, so the label is clickable rather than a wall of URL.
     *
     * @param  string $url
     * @param  string $label
     * @return string
     */
    public function link(string $url, string $label): string
    {
        if (!$this->links || $url === '') {
            return $label;
        }

        return "\e]8;;" . $url . "\e\\" . $label . "\e]8;;\e\\";
    }

    /**
     * The text as the terminal shows it, without escapes, for width maths.
     *
     * @param  string $text
     * @return string
     */
    public static function strip(string $text): string
    {
        $text = preg_replace('/\e]8;;[^\e]*\e\\\\/', '', $text) ?? $text;

        return preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text;
    }
}
