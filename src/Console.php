<?php

declare(strict_types=1);

namespace RepoDownloader;

final class Console
{
    public static function line(string $msg): void
    {
        fwrite(STDOUT, $msg . PHP_EOL);
    }

    public static function fail(string $msg): never
    {
        fwrite(STDERR, "Error: $msg" . PHP_EOL);
        exit(1);
    }
}
