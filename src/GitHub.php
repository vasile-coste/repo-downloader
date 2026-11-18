<?php

declare(strict_types=1);

namespace RepoDownloader;

/** GitHub REST API. */
final class GitHub extends ReleaseHost
{
    /** @param ?string $token raises the API rate limit (60/h -> 5000/h) and gives access to private repos */
    public function __construct(?string $token = null)
    {
        parent::__construct('https://api.github.com', $token);
    }

    public function latestRelease(string $owner, string $repo): ?array
    {
        return $this->getOrNull("/repos/$owner/$repo/releases/latest");
    }

    /** The API lists releases newest first. */
    public function latestPrerelease(string $owner, string $repo): ?array
    {
        foreach ($this->get("/repos/$owner/$repo/releases?per_page=30") as $r) {
            if (!empty($r['prerelease']) && empty($r['draft'])) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Release assets are fetched through their API url (asset 'url') with
     * Accept: application/octet-stream, so private repos work too.
     */
    protected function headers(string $accept, bool $withToken): array
    {
        $h = [
            'Accept'               => $accept === 'application/json' || $accept === '*/*' ? 'application/vnd.github+json' : $accept,
            'User-Agent'           => self::USER_AGENT,
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        if ($this->token && $withToken) {
            $h['Authorization'] = "Bearer $this->token";
        }
        return $h;
    }
}
