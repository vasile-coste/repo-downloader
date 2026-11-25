<?php

declare(strict_types=1);

namespace RepoDownloader;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Turns repo URLs into a host client plus owner/repo. github.com uses GitHub,
 * gitlab.com GitLab; any other server is asked whether it runs Gitea/Forgejo,
 * otherwise it is treated as a self-hosted GitLab.
 *
 * Tokens: GITHUB_TOKEN, GITEA_TOKEN, GITLAB_TOKEN environment variables.
 */
final class Hosts
{
    /** @var array<string, ReleaseHost> */
    private array $hosts = [];

    /** @param int $requestDelay seconds between requests to the same non-GitHub server */
    public function __construct(private readonly int $requestDelay = 0)
    {
    }

    /** @return ?array{host: ReleaseHost, owner: string, repo: string} null for an unrecognised URL */
    public function resolve(string $url): ?array
    {
        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || !in_array($parts['scheme'] ?? 'https', ['http', 'https'], true)) {
            return null;
        }
        // Drop GitLab's "/-/releases..." and Gitea/GitHub's "/releases..." tails.
        $path = preg_replace('~/-(/.*)?$~', '', $parts['path'] ?? '');
        $path = preg_replace('~/(releases|tags)(/.*)?$~', '', $path);
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        if (count($segments) < 2) {
            return null;
        }

        $client = $this->hosts[$host] ??= $this->detect(($parts['scheme'] ?? 'https') . "://$host" . (isset($parts['port']) ? ":{$parts['port']}" : ''), $host);
        if (!$client instanceof GitLab) {
            $segments = array_slice($segments, 0, 2);   // owner/repo/anything-else
        }
        $repo = preg_replace('/\.git$/', '', array_pop($segments));
        return ['host' => $client, 'owner' => implode('/', $segments), 'repo' => $repo];
    }

    private function detect(string $base, string $host): ReleaseHost
    {
        return match (true) {
            $host === 'github.com' || $host === 'www.github.com' => new GitHub(self::env('GITHUB_TOKEN')),
            $host === 'gitlab.com'                               => new GitLab($base, self::env('GITLAB_TOKEN'), $this->requestDelay),
            $this->isGitea($base)                                => new Gitea($base, self::env('GITEA_TOKEN'), $this->requestDelay),
            default                                              => new GitLab($base, self::env('GITLAB_TOKEN'), $this->requestDelay),
        };
    }

    private function isGitea(string $base): bool
    {
        try {
            $response = Throttle::run($base, $this->requestDelay, fn() => (new Client())->get("$base/api/v1/version", [
                RequestOptions::TIMEOUT     => 15,
                RequestOptions::HTTP_ERRORS => false,
            ]));
        } catch (GuzzleException) {
            return false;
        }
        $data = json_decode((string) $response->getBody(), true);
        return $response->getStatusCode() === 200 && is_array($data) && isset($data['version']);
    }

    private static function env(string $name): ?string
    {
        return getenv($name) ?: null;
    }
}
