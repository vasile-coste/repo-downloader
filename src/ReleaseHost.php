<?php

declare(strict_types=1);

namespace RepoDownloader;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use RuntimeException;

/**
 * A server hosting repo releases (GitHub, Gitea/Forgejo, GitLab).
 *
 * Every host returns releases in GitHub's shape, so the download rules are the
 * same everywhere:
 *   tag_name, published_at, prerelease, draft, zipball_url (source zip),
 *   assets: list of {name, url (what to download), browser_download_url}
 */
abstract class ReleaseHost
{
    protected const string USER_AGENT = 'repo-downloader-scraper';

    protected readonly Client $http;

    /**
     * @param string  $api   API base URL, without a trailing slash
     * @param ?string $token sent only to the API's own host
     * @param int     $requestDelay seconds to wait between requests to this server (0 = none)
     */
    public function __construct(
        protected readonly string $api,
        protected readonly ?string $token = null,
        protected readonly int $requestDelay = 0,
    ) {
        // Guzzle follows redirects and drops the Authorization header when a
        // redirect leaves the original host (asset downloads redirect to a CDN).
        $this->http = new Client([RequestOptions::ALLOW_REDIRECTS => ['max' => 10]]);
    }

    /** Latest stable release, or null when the repo has none. */
    abstract public function latestRelease(string $owner, string $repo): ?array;

    /** Newest non-draft prerelease, or null. */
    abstract public function latestPrerelease(string $owner, string $repo): ?array;

    /** Request headers; $withToken is false for URLs outside the API's host. */
    abstract protected function headers(string $accept, bool $withToken): array;

    /** Streams a file to disk via a .part file. */
    public function download(string $url, string $target, bool $isAsset): void
    {
        $tmp = "$target.part";
        try {
            Throttle::run($url, $this->requestDelay, fn() => $this->http->get($url, [
                RequestOptions::SINK            => $tmp,
                RequestOptions::HEADERS         => $this->headers($isAsset ? 'application/octet-stream' : '*/*', $this->isOwnHost($url)),
                RequestOptions::CONNECT_TIMEOUT => 30,
                // Abort when the transfer stalls below 1 KB/s for 60 s.
                'curl'                          => [CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60],
            ]));
        } catch (GuzzleException $e) {
            @unlink($tmp);
            throw new RuntimeException("download failed: " . $e->getMessage());
        }
        rename($tmp, $target);
    }

    /** @throws RuntimeException with the HTTP status as code */
    protected function get(string $path): array
    {
        try {
            $response = Throttle::run($this->api, $this->requestDelay, fn() => $this->http->get($this->api . $path, [
                RequestOptions::HEADERS     => $this->headers('application/json', true),
                RequestOptions::TIMEOUT     => 30,
                RequestOptions::HTTP_ERRORS => false,
            ]));
        } catch (GuzzleException $e) {
            throw new RuntimeException("API request failed: " . $e->getMessage());
        }
        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status !== 200 || !is_array($data)) {
            $msg = is_array($data) ? ($data['message'] ?? $data['error'] ?? '') : '';
            $msg = is_string($msg) ? $msg : json_encode($msg);
            throw new RuntimeException("API $path returned HTTP $status" . ($msg ? ": $msg" : ''), $status);
        }
        return $data;
    }

    /** get() that returns null on 404 instead of throwing. */
    protected function getOrNull(string $path): ?array
    {
        try {
            return $this->get($path);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    private function isOwnHost(string $url): bool
    {
        return strcasecmp((string) parse_url($url, PHP_URL_HOST), (string) parse_url($this->api, PHP_URL_HOST)) === 0;
    }
}
