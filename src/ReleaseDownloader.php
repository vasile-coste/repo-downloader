<?php

declare(strict_types=1);

namespace RepoDownloader;

use RuntimeException;

/** Picks the files of one release and downloads the ones that are missing. */
final class ReleaseDownloader
{
    public function __construct(
        private readonly GitHub $github,
        private readonly Config $config,
        private readonly string $outDir,
        private readonly bool $dryRun = false,
    ) {
    }

    /**
     * Returns how many files were missing (i.e. downloaded, or would be with
     * --dry-run). 0 means the release was already complete.
     */
    public function process(array $release, string $repo): int
    {
        $isPre = !empty($release['prerelease']);
        $version = self::version($release);
        $dir = "$this->outDir/" . FileName::safe($repo) . "/$version" . ($isPre ? '-prerelease' : '');
        Console::line("  -- " . ($isPre ? 'prerelease ' : 'release ') . ($release['tag_name'] ?? '?') . " -> $dir");

        $missing = 0;
        foreach ($this->pick($release, $repo, $version) as $d) {
            if ($isPre) {
                $d['name'] = FileName::withSuffix($d['name'], '-prerelease');
            }
            $target = "$dir/" . FileName::safe($d['name']);
            if (is_file($target) && filesize($target) > 0) {
                Console::line("     = [{$d['kind']}] {$d['name']} (already downloaded)");
                continue;
            }
            Console::line("     + [{$d['kind']}] {$d['name']}");
            $missing++;
            if ($this->dryRun) {
                continue;
            }
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                Console::line("     ! cannot create $dir");
                return $missing;
            }
            try {
                $this->github->download($d['url'], $target, $d['kind'] !== 'source');
                Console::line(sprintf("       %s (%.1f MB)", basename($target), filesize($target) / 1048576));
            } catch (RuntimeException $e) {
                Console::line("     ! " . $e->getMessage());
            }
        }
        return $missing;
    }

    /** @return list<array{name: string, url: string, kind: string}> */
    private function pick(array $release, string $repo, string $version): array
    {
        $assets = array_values(array_filter(
            $release['assets'] ?? [],
            fn(array $a) => !self::isChecksumOrMeta($a['name'])
        ));

        $downloads = [];

        if ($this->config->source && !empty($release['zipball_url'])) {
            $downloads[] = [
                'name' => FileName::safe($repo) . '-' . $version . '-source.zip',
                'url'  => $release['zipball_url'],
                'kind' => 'source',
            ];
        }

        $recognised = false;
        foreach ($this->config->platforms as $platform) {
            $own = array_values(array_filter(
                $assets,
                fn(array $a) => $this->config->platformOf($a['name']) === $platform
            ));
            $recognised = $recognised || $own;
            if ($platform->enabled) {
                foreach ($platform->pickBuilds($own) as $a) {
                    $downloads[] = ['name' => $a['name'], 'url' => $a['url'], 'kind' => $platform->name];
                }
            }
        }

        if ($this->config->uncategorized && !$recognised && !empty($assets[0]['browser_download_url'])) {
            $downloads[] = [
                'name' => FileName::safe($repo) . '-' . $version . '-uncategorized.zip',
                'url'  => $assets[0]['browser_download_url'],
                'kind' => 'uncategorized',
            ];
        }

        return $downloads;
    }

    /** Tag name if it looks usable, otherwise the publish date. */
    private static function version(array $release): string
    {
        $tag = trim((string) ($release['tag_name'] ?? ''));
        if ($tag !== '') {
            return FileName::safe($tag);
        }
        $date = $release['published_at'] ?? $release['created_at'] ?? null;
        return $date ? substr($date, 0, 10) : 'unknown';
    }

    private static function isChecksumOrMeta(string $name): bool
    {
        return (bool) preg_match('/\.(sha\d*|sha256sum|md5|asc|sig|blockmap|yml|yaml|json|txt|sbom)$/i', $name);
    }
}
