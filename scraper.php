<?php
/**
 * GitHub release asset scraper.
 *
 * Reads repos.json and, for each repository, downloads into {Repo}/{version}/
 * the source code zip and the platform builds configured in config.php
 * (by default Windows x64 + arm64, macOS universal, Android arm64).
 *
 * Without --all, the latest prerelease is downloaded instead when the repo has
 * no stable release, or when the latest stable release is already downloaded
 * and a newer prerelease exists. Prerelease files get a "-prerelease" suffix.
 *
 * Usage:
 *   php scraper.php [--all] [--limit=N] [--dry-run] [--out=DIR] [--repos=FILE]
 *                   [--config=FILE] [--platforms=LIST] [--arch=LIST] [--no-source]
 *
 *   --all       process every release instead of only the latest one
 *   --limit=N   with --all, process at most N most recent releases
 *   --dry-run   only print what would be downloaded
 *   --out=DIR   output directory (default: download/ next to the script)
 *   --repos=FILE repos list (default: repos.json next to the script)
 *   --config=FILE   download settings (default: config.php next to the script)
 *   --platforms=LIST only these platforms, e.g. --platforms=windows,android
 *   --arch=LIST     these architectures for every platform, e.g. --arch=arm64,x64
 *   --no-source     skip the source code zip
 *
 * The code lives in src/ (RepoDownloader namespace); this file only wires it up.
 *
 * Set GITHUB_TOKEN in the environment to raise the API rate limit (60/h -> 5000/h).
 */

declare(strict_types=1);

use RepoDownloader\Config;
use RepoDownloader\Console;
use RepoDownloader\GitHub;
use RepoDownloader\ReleaseDownloader;
use RepoDownloader\Scraper;

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, 'Error: PHP 8.4 or newer is required, found ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

$opts = getopt('', ['all', 'limit:', 'dry-run', 'out:', 'repos:', 'config:', 'platforms:', 'arch:', 'no-source']);
$csv = fn(string $value) => array_values(array_filter(array_map(fn($v) => strtolower(trim($v)), explode(',', $value))));

try {
    $config = Config::load($opts['config'] ?? __DIR__ . '/config.php');
    if (isset($opts['no-source'])) {
        $config->source = false;
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

$reposFile = $opts['repos'] ?? __DIR__ . '/repos.json';
$repos = json_decode((string) @file_get_contents($reposFile), true);
if (!is_array($repos)) {
    Console::fail("Cannot read a JSON array of repo URLs from $reposFile");
}

$github = new GitHub(getenv('GITHUB_TOKEN') ?: null);
$downloader = new ReleaseDownloader(
    $github,
    $config,
    outDir: rtrim($opts['out'] ?? __DIR__ . '/download', '/'),
    dryRun: isset($opts['dry-run']),
);
$scraper = new Scraper(
    $github,
    $downloader,
    allReleases: isset($opts['all']),
    limit: isset($opts['limit']) ? max(1, (int) $opts['limit']) : null,
);
$scraper->run($repos);
