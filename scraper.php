<?php
/**
 * GitHub release asset scraper.
 *
 * Reads repos.json and, for each repository, downloads into {Repo}/{version}/:
 *   - the source code zip
 *   - Windows build(s): x64 + arm64 when several exist, portable builds ignored
 *   - macOS: universal .dmg preferred, otherwise a .zip
 *   - Android: arm64-v8a .apk preferred
 *
 * Usage:
 *   php scraper.php [--all] [--limit=N] [--dry-run] [--out=DIR] [--repos=FILE]
 *
 *   --all       process every release instead of only the latest one
 *   --limit=N   with --all, process at most N most recent releases
 *   --dry-run   only print what would be downloaded
 *   --out=DIR   output directory (default: download/ next to the script)
 *   --repos=FILE repos list (default: repos.json next to the script)
 *
 * Set GITHUB_TOKEN in the environment to raise the API rate limit (60/h -> 5000/h).
 */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, 'Error: PHP 8.4 or newer is required, found ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

const API = 'https://api.github.com';

const USER_AGENT = 'repo-downloader-scraper';

$opts = getopt('', ['all', 'limit:', 'dry-run', 'out:', 'repos:']);
$allReleases = isset($opts['all']);
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;
$dryRun = isset($opts['dry-run']);
$outDir = rtrim($opts['out'] ?? __DIR__ . '/download', '/');
$reposFile = $opts['repos'] ?? __DIR__ . '/repos.json';
$token = getenv('GITHUB_TOKEN') ?: null;

$repos = json_decode((string) @file_get_contents($reposFile), true);
if (!is_array($repos)) {
    fail("Cannot read a JSON array of repo URLs from $reposFile");
}

foreach ($repos as $url) {
    if (!preg_match('~github\.com/([^/\s]+)/([^/\s#?]+)~i', (string) $url, $m)) {
        logLine("! Skipping unrecognised URL: $url");
        continue;
    }
    [$owner, $repo] = [$m[1], preg_replace('/\.git$/', '', $m[2])];
    logLine("== $owner/$repo");

    try {
        $releases = $allReleases
            ? fetchAllReleases($owner, $repo, $token, $limit)
            : [apiGet("/repos/$owner/$repo/releases/latest", $token)];
    } catch (RuntimeException $e) {
        logLine("  ! " . $e->getMessage());
        continue;
    }

    foreach ($releases as $release) {
        processRelease($release, $repo, $outDir, $token, $dryRun);
    }
}

// ---------------------------------------------------------------------------

function processRelease(array $release, string $repo, string $outDir, ?string $token, bool $dryRun): void
{
    $version = releaseVersion($release);
    $dir = "$outDir/" . safeName($repo) . "/$version";
    logLine("  -- release " . ($release['tag_name'] ?? '?') . " -> $dir");

    $assets = array_values(array_filter(
        $release['assets'] ?? [],
        fn(array $a) => !isChecksumOrMeta($a['name'])
    ));

    $downloads = [];

    if (!empty($release['zipball_url'])) {
        $downloads[] = [
            'name' => safeName($repo) . '-' . $version . '-source.zip',
            'url'  => $release['zipball_url'],
            'kind' => 'source',
        ];
    }
    foreach (pickWindows($assets) as $a) {
        $downloads[] = assetDownload($a, 'windows');
    }
    if ($a = pickMac($assets)) {
        $downloads[] = assetDownload($a, 'macos');
    }
    if ($a = pickAndroid($assets)) {
        $downloads[] = assetDownload($a, 'android');
    }

    foreach ($downloads as $d) {
        $target = "$dir/" . safeName($d['name']);
        if (is_file($target) && filesize($target) > 0) {
            logLine("     = [{$d['kind']}] {$d['name']} (already downloaded)");
            continue;
        }
        logLine("     + [{$d['kind']}] {$d['name']}");
        if ($dryRun) {
            continue;
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            logLine("     ! cannot create $dir");
            return;
        }
        try {
            download($d['url'], $target, $token, $d['kind'] !== 'source');
        } catch (RuntimeException $e) {
            logLine("     ! " . $e->getMessage());
        }
    }
}

function assetDownload(array $asset, string $kind): array
{
    return ['name' => $asset['name'], 'url' => $asset['url'], 'kind' => $kind];
}

/** Tag name if it looks usable, otherwise the publish date. */
function releaseVersion(array $release): string
{
    $tag = trim((string) ($release['tag_name'] ?? ''));
    if ($tag !== '') {
        return safeName($tag);
    }
    $date = $release['published_at'] ?? $release['created_at'] ?? null;
    return $date ? substr($date, 0, 10) : 'unknown';
}

// --- asset selection ---------------------------------------------------------

function isChecksumOrMeta(string $name): bool
{
    return (bool) preg_match('/\.(sha\d*|sha256sum|md5|asc|sig|blockmap|yml|yaml|json|txt|sbom)$/i', $name);
}

function isPortable(string $name): bool
{
    return stripos($name, 'portable') !== false;
}

function isWindows(string $name): bool
{
    $n = strtolower($name);
    if (preg_match('/\.(exe|msi|msix|appx|appxbundle)$/', $n)) {
        return true;
    }
    return (bool) preg_match('/(^|[^a-z])(win|windows|win32|win64)([^a-z]|$)/', $n)
        && preg_match('/\.(zip|7z)$/', $n);
}

function isMac(string $name): bool
{
    $n = strtolower($name);
    if (str_ends_with($n, '.dmg') || str_ends_with($n, '.pkg')) {
        return true;
    }
    return (bool) preg_match('/(mac|macos|osx|darwin|apple)/', $n)
        && preg_match('/\.(zip|tar\.gz|tgz)$/', $n);
}

function archOf(string $name): ?string
{
    $n = strtolower($name);
    if (preg_match('/(arm64|aarch64)/', $n)) {
        return 'arm64';
    }
    if (preg_match('/(x64|x86[_-]64|amd64|win64)/', $n)) {
        return 'x64';
    }
    if (preg_match('/(x86|i[36]86|win32|ia32)/', $n)) {
        return 'x86';
    }
    return null;
}

/** Installers before archives. */
function windowsFormatRank(string $name): int
{
    $n = strtolower($name);
    return match (true) {
        str_ends_with($n, '.exe') => 0,
        str_ends_with($n, '.msi') => 1,
        str_ends_with($n, '.msix'), str_ends_with($n, '.appx'), str_ends_with($n, '.appxbundle') => 2,
        default => 3,
    };
}

/**
 * Windows: ignore portable builds. If there is more than one candidate,
 * take the best x64 and the best arm64 build; otherwise take the single one.
 */
function pickWindows(array $assets): array
{
    $candidates = array_values(array_filter(
        $assets,
        fn(array $a) => isWindows($a['name']) && !isPortable($a['name'])
    ));
    if (count($candidates) <= 1) {
        return $candidates;
    }

    usort($candidates, fn($a, $b) => windowsFormatRank($a['name']) <=> windowsFormatRank($b['name']));

    $picked = [];
    foreach (['x64', 'arm64'] as $arch) {
        foreach ($candidates as $a) {
            if (archOf($a['name']) === $arch) {
                $picked[] = $a;
                break;
            }
        }
    }
    // No arch-tagged builds: fall back to the best generic one.
    if (!$picked) {
        foreach ($candidates as $a) {
            if (archOf($a['name']) === null) {
                return [$a];
            }
        }
        return [$candidates[0]];
    }
    return $picked;
}

/** macOS: universal dmg > universal zip > any dmg > any zip. */
function pickMac(array $assets): ?array
{
    $mac = array_values(array_filter($assets, fn(array $a) => isMac($a['name'])));
    $universal = fn(array $a) => (bool) preg_match('/universal/i', $a['name']);
    $dmg = fn(array $a) => (bool) preg_match('/\.dmg$/i', $a['name']);
    $zip = fn(array $a) => (bool) preg_match('/\.zip$/i', $a['name']);

    $tiers = [
        fn($a) => $universal($a) && $dmg($a),
        fn($a) => $universal($a) && $zip($a),
        $dmg,
        $zip,
    ];
    foreach ($tiers as $match) {
        foreach ($mac as $a) {
            if ($match($a)) {
                return $a;
            }
        }
    }
    return null;
}

/** Android: arm64-v8a > universal > any release apk > any apk. */
function pickAndroid(array $assets): ?array
{
    $apks = array_values(array_filter($assets, fn(array $a) => (bool) preg_match('/\.apk$/i', $a['name'])));
    $tiers = [
        fn($a) => (bool) preg_match('/arm64[-_]?v8a|arm64|aarch64/i', $a['name']),
        fn($a) => (bool) preg_match('/universal/i', $a['name']),
        fn($a) => stripos($a['name'], 'release') !== false,
        fn($a) => true,
    ];
    foreach ($tiers as $match) {
        foreach ($apks as $a) {
            if ($match($a)) {
                return $a;
            }
        }
    }
    return null;
}

// --- HTTP -------------------------------------------------------------------

function fetchAllReleases(string $owner, string $repo, ?string $token, ?int $limit): array
{
    $all = [];
    for ($page = 1; ; $page++) {
        $batch = apiGet("/repos/$owner/$repo/releases?per_page=100&page=$page", $token);
        $batch = array_values(array_filter($batch, fn($r) => empty($r['draft'])));
        array_push($all, ...$batch);
        if (count($batch) < 100 || ($limit && count($all) >= $limit)) {
            break;
        }
    }
    return $limit ? array_slice($all, 0, $limit) : $all;
}

function apiGet(string $path, ?string $token): array
{
    try {
        $response = http()->get(API . $path, [
            RequestOptions::HEADERS     => headers($token, 'application/vnd.github+json'),
            RequestOptions::TIMEOUT     => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    } catch (GuzzleException $e) {
        throw new RuntimeException("API request failed: " . $e->getMessage());
    }
    $status = $response->getStatusCode();
    $data = json_decode((string) $response->getBody(), true);
    if ($status !== 200 || !is_array($data)) {
        $msg = is_array($data) ? ($data['message'] ?? '') : '';
        throw new RuntimeException("API $path returned HTTP $status" . ($msg ? ": $msg" : ''));
    }
    return $data;
}

/**
 * Streams a file to disk via a .part file. Release assets are fetched through
 * their API url with Accept: application/octet-stream so private repos work too.
 */
function download(string $url, string $target, ?string $token, bool $isAsset): void
{
    $tmp = "$target.part";
    try {
        http()->get($url, [
            RequestOptions::SINK            => $tmp,
            RequestOptions::HEADERS         => headers($token, $isAsset ? 'application/octet-stream' : 'application/vnd.github+json'),
            RequestOptions::CONNECT_TIMEOUT => 30,
            // Abort when the transfer stalls below 1 KB/s for 60 s.
            'curl'                          => [CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60],
        ]);
    } catch (GuzzleException $e) {
        @unlink($tmp);
        throw new RuntimeException("download failed: " . $e->getMessage());
    }
    rename($tmp, $target);
    logLine(sprintf("       %s (%.1f MB)", basename($target), filesize($target) / 1048576));
}

/**
 * Shared client. Guzzle follows redirects and drops the Authorization header
 * when a redirect leaves the original host (asset downloads redirect to a CDN).
 */
function http(): Client
{
    static $client = new Client([RequestOptions::ALLOW_REDIRECTS => ['max' => 10]]);
    return $client;
}

function headers(?string $token, string $accept): array
{
    $h = ['Accept' => $accept, 'User-Agent' => USER_AGENT, 'X-GitHub-Api-Version' => '2022-11-28'];
    if ($token) {
        $h['Authorization'] = "Bearer $token";
    }
    return $h;
}

// --- misc -------------------------------------------------------------------

function safeName(string $name): string
{
    return trim(preg_replace('/[^A-Za-z0-9._+-]+/', '_', $name), '._') ?: 'unnamed';
}

function logLine(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg): never
{
    fwrite(STDERR, "Error: $msg" . PHP_EOL);
    exit(1);
}
