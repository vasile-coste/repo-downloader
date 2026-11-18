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
        private readonly Hosts $hosts,
        private readonly ReleaseDownloader $downloader,
        private readonly bool $prerelease = true,
    ) {
    }

    /** @param list<string> $urls repo or /releases URLs (GitHub, Gitea/Forgejo, GitLab) */
    public function run(array $urls): void
    {
        $contor = 0;
        $total = count($urls);
        foreach ($urls as $url) {
            $contor ++;
            Console::line("Scraping repo $contor / $total ...");
            try {
                $target = $this->hosts->resolve((string) $url);
                if (!$target) {
                    Console::line("! Skipping unrecognised URL: $url");
                    continue;
                }
                ['host' => $host, 'owner' => $owner, 'repo' => $repo] = $target;
                Console::line("== $owner/$repo (" . parse_url((string) $url, PHP_URL_HOST) . ")");
                $this->scrape($host, $owner, $repo);
            } catch (RuntimeException $e) {
                Console::line("  ! " . $e->getMessage());
            }
        }
    }

    private function scrape(ReleaseHost $host, string $owner, string $repo): void
    {
        $latest = $host->latestRelease($owner, $repo);
        if (!$latest) {
            Console::line("  no stable release");
        } elseif ($this->downloader->process($host, $latest, $repo) > 0) {
            return;
        }
        if (!$this->prerelease) {
            return;
        }

        // Only the newest prerelease, never older ones.
        $pre = $host->latestPrerelease($owner, $repo);
        if (!$pre) {
            Console::line("  no prerelease found");
        } elseif ($latest && ($pre['published_at'] ?? '') <= ($latest['published_at'] ?? '')) {
            Console::line("  latest prerelease {$pre['tag_name']} is older than the stable release, skipping");
        } else {
            $this->downloader->process($host, $pre, $repo);
        }
    }
}
