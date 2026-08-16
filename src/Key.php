<?php

namespace D3R\Tickets;

/**
 * Ticket keys as they appear in branch names.
 *
 * Branches spell them both ways, LF-7873 and LF_7873, and a key must not match a
 * longer number, so LF-787 never claims LF-7873's branch.
 */
final class Key
{
    private const string PATTERN = '([A-Z]{2,10})[-_](\d+)';

    /**
     * The ticket key a branch names, if any.
     *
     * @param  string $branch
     * @return string|null
     */
    public static function fromBranch(string $branch): ?string
    {
        return self::allFromBranch($branch)[0] ?? null;
    }

    /**
     * Every key-shaped token in a branch, in order.
     *
     * Branch names carry decoys: revert-7364-revert-7347-LF-7979 looks like it names
     * REVERT-7364. Only Jira can say which token is a real ticket, so hand back all
     * of them and let the caller keep the one that resolves.
     *
     * @param  string $branch
     * @return string[]
     */
    public static function allFromBranch(string $branch): array
    {
        if (preg_match_all('/' . self::PATTERN . '/i', $branch, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $keys = array_map(
            static fn(array $match) => strtoupper($match[1]) . '-' . $match[2],
            $matches
        );

        return array_values(array_unique($keys));
    }

    /**
     * Whether text references this key as a whole token.
     *
     * @param  string $text
     * @param  string $key
     * @return bool
     */
    public static function mentioned(string $text, string $key): bool
    {
        foreach ([$key, str_replace('-', '_', $key)] as $spelling) {
            if (preg_match('/(?<![A-Z0-9])' . preg_quote($spelling, '/') . '(?![0-9])/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a string is a complete key rather than part of one.
     *
     * @param  string $value
     * @return bool
     */
    public static function isComplete(string $value): bool
    {
        return preg_match('/^' . self::PATTERN . '$/i', $value) === 1;
    }

    /**
     * The project a key belongs to.
     *
     * @param  string $key
     * @return string
     */
    public static function project(string $key): string
    {
        return strtoupper(strtok($key, '-') ?: '');
    }
}
