<?php

declare(strict_types=1);

namespace RepoDownloader;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use RuntimeException;

/** GitHub REST API and file downloads. */
final class GitHub
{
    private const string API = 'https://api.github.com';

    private const string USER_AGENT = 'repo-downloader-scraper';

    private readonly Client $http;

    /** @param ?string $token raises the API rate limit (60/h -> 5000/h) and gives access to private repos */
    public function __construct(private readonly ?string $token = null)
    {
        // Guzzle follows redirects and drops the Authorization header when a
        // redirect leaves the original host (asset downloads redirect to a CDN).
        $this->http = new Client([RequestOptions::ALLOW_REDIRECTS => ['max' => 10]]);
    }

    /** Latest stable release, or null when the repo has none. */
    public function latestRelease(string $owner, string $repo): ?array
    {
        try {
            return $this->get("/repos/$owner/$repo/releases/latest");
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /** Newest non-draft prerelease, or null. The API lists releases newest first. */
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
     * Streams a file to disk via a .part file. Release assets are fetched through
     * their API url with Accept: application/octet-stream so private repos work too.
     */
    public function download(string $url, string $target, bool $isAsset): void
    {
        $tmp = "$target.part";
        try {
            $this->http->get($url, [
                RequestOptions::SINK            => $tmp,
                RequestOptions::HEADERS         => $this->headers($isAsset ? 'application/octet-stream' : 'application/vnd.github+json'),
                RequestOptions::CONNECT_TIMEOUT => 30,
                // Abort when the transfer stalls below 1 KB/s for 60 s.
                'curl'                          => [CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60],
            ]);
        } catch (GuzzleException $e) {
            @unlink($tmp);
            throw new RuntimeException("download failed: " . $e->getMessage());
        }
        rename($tmp, $target);
    }

    /** @throws RuntimeException with the HTTP status as code */
    private function get(string $path): array
    {
        try {
            $response = $this->http->get(self::API . $path, [
                RequestOptions::HEADERS     => $this->headers('application/vnd.github+json'),
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
            throw new RuntimeException("API $path returned HTTP $status" . ($msg ? ": $msg" : ''), $status);
        }
        return $data;
    }

    private function headers(string $accept): array
    {
        $h = ['Accept' => $accept, 'User-Agent' => self::USER_AGENT, 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($this->token) {
            $h['Authorization'] = "Bearer $this->token";
        }
        return $h;
    }
}
