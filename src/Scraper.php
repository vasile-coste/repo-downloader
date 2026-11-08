<?php

declare(strict_types=1);

namespace RepoDownloader;

use RuntimeException;

/**
 * Walks the repo list and downloads the latest release of each repo, falling
 * back to the latest prerelease when there is no stable release or it is
 * already downloaded.
 */
final class Scraper
{
    public function __construct(
        private readonly GitHub $github,
        private readonly ReleaseDownloader $downloader,
        private readonly bool $prerelease = true,
    ) {
    }

    /** @param list<string> $urls GitHub repo or /releases URLs */
    public function run(array $urls): void
    {
        $contor = 0;
        $total = count($urls);
        foreach ($urls as $url) {
            $contor ++;
            Console::line("Scraping repo $contor / $total ...");
            if (!preg_match('~github\.com/([^/\s]+)/([^/\s#?]+)~i', (string) $url, $m)) {
                Console::line("! Skipping unrecognised URL: $url");
                continue;
            }
            [$owner, $repo] = [$m[1], preg_replace('/\.git$/', '', $m[2])];
            Console::line("== $owner/$repo");

            try {
                $this->scrape($owner, $repo);
            } catch (RuntimeException $e) {
                Console::line("  ! " . $e->getMessage());
            }
        }
    }

    private function scrape(string $owner, string $repo): void
    {
        $latest = $this->github->latestRelease($owner, $repo);
        if (!$latest) {
            Console::line("  no stable release");
        } elseif ($this->downloader->process($latest, $repo) > 0) {
            return;
        }
        if (!$this->prerelease) {
            return;
        }

        // Only the newest prerelease, never older ones.
        $pre = $this->github->latestPrerelease($owner, $repo);
        if (!$pre) {
            Console::line("  no prerelease found");
        } elseif ($latest && ($pre['published_at'] ?? '') <= ($latest['published_at'] ?? '')) {
            Console::line("  latest prerelease {$pre['tag_name']} is older than the stable release, skipping");
        } else {
            $this->downloader->process($pre, $repo);
        }
    }
}
