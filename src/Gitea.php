<?php

declare(strict_types=1);

namespace RepoDownloader;

/** Gitea / Forgejo / Codeberg API (v1). Its releases are already close to GitHub's. */
final class Gitea extends ReleaseHost
{
    public function __construct(string $baseUrl, ?string $token = null)
    {
        parent::__construct(rtrim($baseUrl, '/') . '/api/v1', $token);
    }

    public function latestRelease(string $owner, string $repo): ?array
    {
        $r = $this->getOrNull("/repos/$owner/$repo/releases/latest");
        return $r ? $this->normalize($r, $owner, $repo) : null;
    }

    /** The API lists releases newest first. */
    public function latestPrerelease(string $owner, string $repo): ?array
    {
        foreach ($this->get("/repos/$owner/$repo/releases?limit=30") as $r) {
            if (!empty($r['prerelease']) && empty($r['draft'])) {
                return $this->normalize($r, $owner, $repo);
            }
        }
        return null;
    }

    protected function headers(string $accept, bool $withToken): array
    {
        $h = ['Accept' => $accept, 'User-Agent' => self::USER_AGENT];
        if ($this->token && $withToken) {
            $h['Authorization'] = "token $this->token";
        }
        return $h;
    }

    /**
     * Gitea assets have no API 'url'; they download from browser_download_url.
     * The source zip comes from the API, as some servers block the web archive
     * link for non-browser clients.
     */
    private function normalize(array $r, string $owner, string $repo): array
    {
        if (!empty($r['zipball_url']) && ($r['tag_name'] ?? '') !== '') {
            $r['zipball_url'] = "$this->api/repos/$owner/$repo/archive/" . rawurlencode($r['tag_name']) . '.zip';
        }
        $r['assets'] = array_map(
            fn(array $a) => ['url' => $a['browser_download_url'] ?? ''] + $a,
            $r['assets'] ?? []
        );
        return $r;
    }
}
