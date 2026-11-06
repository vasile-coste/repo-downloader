<?php

declare(strict_types=1);

namespace RepoDownloader;

final class FileName
{
    public static function safe(string $name): string
    {
        return trim(preg_replace('/[^A-Za-z0-9._+-]+/', '_', $name), '._') ?: 'unnamed';
    }

    /** Inserts $suffix before the extension: app.tar.gz -> app-prerelease.tar.gz */
    public static function withSuffix(string $name, string $suffix): string
    {
        return preg_replace('/(\.tar\.(gz|xz|bz2|zst)|\.[A-Za-z0-9]{1,10})?$/i', $suffix . '$1', $name, 1);
    }
}
