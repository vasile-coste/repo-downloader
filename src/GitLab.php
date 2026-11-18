<?php

declare(strict_types=1);

namespace RepoDownloader;

/**
 * GitLab API (v4), gitlab.com or self-hosted. Releases are converted to
 * GitHub's shape. GitLab has no prereleases, so only stable releases are found.
 */
final class GitLab extends ReleaseHost
{
    public function __construct(string $baseUrl, ?string $token = null)
    {
        parent::__construct(rtrim($baseUrl, '/') . '/api/v4', $token);
    }

    /** $owner may contain subgroups ("group/subgroup"). The API lists releases newest first. */
    public function latestRelease(string $owner, string $repo): ?array
    {
        $id = rawurlencode("$owner/$repo");
        foreach ($this->getOrNull("/projects/$id/releases?per_page=30") ?? [] as $r) {
            if (empty($r['upcoming_release'])) {
                return self::normalize($r);
            }
        }
        return null;
    }

    public function latestPrerelease(string $owner, string $repo): ?array
    {
        return null;
    }

    protected function headers(string $accept, bool $withToken): array
    {
        $h = ['Accept' => $accept, 'User-Agent' => self::USER_AGENT];
        if ($this->token && $withToken) {
            $h['PRIVATE-TOKEN'] = $this->token;
        }
        return $h;
    }

    private static function normalize(array $r): array
    {
        $zip = null;
        foreach ($r['assets']['sources'] ?? [] as $s) {
            if (($s['format'] ?? '') === 'zip') {
                $zip = $s['url'];
            }
        }
        $assets = [];
        foreach ($r['assets']['links'] ?? [] as $l) {
            $url = $l['direct_asset_url'] ?? $l['url'] ?? '';
            // Link names are free text; the URL's file name is what platform detection needs.
            $file = basename((string) parse_url($url, PHP_URL_PATH));
            $name = pathinfo($file, PATHINFO_EXTENSION) !== '' ? $file : (string) ($l['name'] ?? $file);
            $assets[] = ['name' => $name, 'url' => $url, 'browser_download_url' => $url];
        }
        return [
            'tag_name'     => $r['tag_name'] ?? '',
            'name'         => $r['name'] ?? '',
            'published_at' => $r['released_at'] ?? $r['created_at'] ?? null,
            'created_at'   => $r['created_at'] ?? null,
            'prerelease'   => false,
            'draft'        => false,
            'zipball_url'  => $zip,
            'assets'       => $assets,
        ];
    }
}
