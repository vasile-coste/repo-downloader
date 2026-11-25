<?php

declare(strict_types=1);

namespace RepoDownloader;

/**
 * Keeps a minimum pause between requests to the same server, counted from the
 * end of the previous request. Some self-hosted Gitea/GitLab servers answer
 * HTTP 403 when hit too often.
 */
final class Throttle
{
    /** @var array<string, float> server host => time its last request finished */
    private static array $last = [];

    /**
     * Runs $request after waiting until $seconds have passed since the last
     * request to $url's host. $seconds <= 0 runs it right away.
     *
     * @template T
     * @param callable(): T $request
     * @return T
     */
    public static function run(string $url, int $seconds, callable $request): mixed
    {
        if ($seconds <= 0) {
            return $request();
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (isset(self::$last[$host])) {
            $left = self::$last[$host] + $seconds - microtime(true);
            if ($left > 0) {
                Console::line(sprintf('  waiting %ds before the next request to %s ...', (int) ceil($left), $host));
                usleep((int) ($left * 1_000_000));
            }
        }
        try {
            return $request();
        } finally {
            self::$last[$host] = microtime(true);
        }
    }
}
