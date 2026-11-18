<?php
/**
 * Release asset scraper for GitHub, Gitea/Forgejo and GitLab repos.
 *
 * Reads github.json and gitlab.json (any repo URL works in either; the host is
 * detected from the URL) and, for each repository, downloads into {Repo}/{version}/
 * the source code zip and the platform builds configured in config.php
 * (by default Windows x64 + arm64, macOS universal, Android arm64).
 *
 * Only the latest release is downloaded. The latest prerelease is downloaded
 * instead when the repo has no stable release, or when the latest stable
 * release is already downloaded and a newer prerelease exists (turn this off
 * with --no-prerelease). Prerelease files get a "-prerelease" suffix.
 *
 * Usage:
 *   php scraper.php [--dry-run] [--out=DIR] [--repos=FILE] [--config=FILE]
 *                   [--platforms=LIST] [--arch=LIST] [--no-source] [--no-prerelease]
 *
 *   --dry-run   only print what would be downloaded
 *   --out=DIR   output directory (default: download/ next to the script)
 *   --repos=FILES  repo lists, comma separated (default: github.json and gitlab.json)
 *   --config=FILE   download settings (default: config.php next to the script)
 *   --platforms=LIST only these platforms, e.g. --platforms=windows,android
 *   --arch=LIST     these architectures for every platform, e.g. --arch=arm64,x64
 *   --no-source     skip the source code zip
 *   --no-prerelease never fall back to the latest prerelease
 *
 * The code lives in src/ (RepoDownloader namespace); this file only wires it up.
 *
 * Set GITHUB_TOKEN in the environment to raise the API rate limit (60/h -> 5000/h).
 * GITEA_TOKEN and GITLAB_TOKEN are used for private repos on those hosts.
 */

declare(strict_types=1);

use RepoDownloader\Config;
use RepoDownloader\Console;
use RepoDownloader\Hosts;
use RepoDownloader\ReleaseDownloader;
use RepoDownloader\Scraper;

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, 'Error: PHP 8.4 or newer is required, found ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

$opts = getopt('', ['dry-run', 'out:', 'repos:', 'config:', 'platforms:', 'arch:', 'no-source', 'no-prerelease']);
$csv = fn(string $value) => array_values(array_filter(array_map(fn($v) => strtolower(trim($v)), explode(',', $value))));

try {
    $config = Config::load($opts['config'] ?? __DIR__ . '/config.php');
    if (isset($opts['no-source'])) {
        $config->source = false;
    }
    if (isset($opts['no-prerelease'])) {
        $config->prerelease = false;
    }
    if (isset($opts['platforms'])) {
        $config->onlyPlatforms($csv($opts['platforms']));
    }
    if (isset($opts['arch'])) {
        $config->withArch($csv($opts['arch']));
    }
} catch (RuntimeException $e) {
    Console::fail($e->getMessage());
}

// Default: every repo list next to the script that exists. --repos takes a
// comma-separated list of files.
$reposFiles = isset($opts['repos'])
    ? array_map('trim', explode(',', $opts['repos']))
    : array_values(array_filter([__DIR__ . '/github.json', __DIR__ . '/gitlab.json'], 'is_file'));
if (!$reposFiles) {
    Console::fail('No repo list found: create github.json and/or gitlab.json, or pass --repos=FILE');
}
$repos = [];
foreach ($reposFiles as $reposFile) {
    $list = json_decode((string) @file_get_contents($reposFile), true);
    if (!is_array($list)) {
        Console::fail("Cannot read a JSON array of repo URLs from $reposFile");
    }
    array_push($repos, ...array_values($list));
}

$downloader = new ReleaseDownloader(
    $config,
    outDir: rtrim($opts['out'] ?? __DIR__ . '/download', '/'),
    dryRun: isset($opts['dry-run']),
);
(new Scraper(new Hosts(), $downloader, prerelease: $config->prerelease))->run($repos);
