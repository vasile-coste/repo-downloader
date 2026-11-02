# repo-downloader

A PHP script that downloads release assets from the GitHub repositories listed in `repos.json`.

For each release it downloads:

| Asset | What gets picked |
|---|---|
| Source code | The release's source code zip |
| Windows | x64 and arm64 builds when several exist; installers (`.exe`, `.msi`) before zips; portable and 32-bit builds are ignored |
| macOS | Universal `.dmg`, otherwise universal `.zip`, otherwise any `.dmg`/mac `.zip` |
| Android | `arm64-v8a` APK, otherwise universal, otherwise a release APK, otherwise any APK |

Assets that don't exist for a release are skipped. Checksums, signatures and other metadata files (`.sha256`, `.sig`, `.blockmap`, `.yml`, …) are never downloaded.

## Requirements

- PHP 8.1 or newer, with the `curl` and `json` extensions

Check with:

```bash
php -v
php -m | grep -E 'curl|json'
```

## Setup

1. List the repositories in `repos.json`. Either the repo URL or its `/releases` URL works:

   ```json
   [
       "https://github.com/owner/another-repo"
   ]
   ```

2. Optional: set a GitHub token (see [GitHub token](#github-token)).

## Usage

```bash
php scraper.php                   # latest release of every repo
php scraper.php --all             # every release
php scraper.php --all --limit=5   # the 5 most recent releases
php scraper.php --dry-run         # list what would be downloaded, download nothing
php scraper.php --out=/some/dir   # save downloads somewhere else
php scraper.php --repos=other.json
```

| Option | Description |
|---|---|
| `--all` | Process every release instead of only the latest one. Draft releases are skipped. |
| `--limit=N` | With `--all`, only the N most recent releases. |
| `--dry-run` | Print what would be downloaded without downloading. |
| `--out=DIR` | Output directory. Default: the script's folder. |
| `--repos=FILE` | Repo list to read. Default: `repos.json` next to the script. |

## Output layout

Downloads go into `{Repo}/{version}/`, where `{version}` is the release tag, or the publish date (`YYYY-MM-DD`) when the release has no tag:

```
Lemuroid/
└── 7/
    ├── Lemuroid-7-source.zip
    └── lemuroid-app-free-bundle-release-arm64-v8a.apk
```

Running the script again is safe: files that already exist are skipped, so only new releases or missing files are downloaded. Downloads are written to a `.part` file first and renamed when complete, so an interrupted run never leaves a broken file behind.

## GitHub token

Without a token GitHub allows 60 API requests per hour. Each repo uses about one request (more with `--all` on repos with hundreds of releases), so a token is only needed for long repo lists or private repos. With a token the limit is 5,000 per hour.

1. Create a token at <https://github.com/settings/tokens>:
   - **Fine-grained token**: "Public repositories (read-only)" is enough for public repos.
   - **Classic token**: no scopes for public repos; tick `repo` for private ones.

2. Pass it as the `GITHUB_TOKEN` environment variable:

   ```bash
   # one run
   GITHUB_TOKEN=ghp_yourtoken php scraper.php

   # current terminal session
   export GITHUB_TOKEN=ghp_yourtoken

   # permanently (zsh)
   echo 'export GITHUB_TOKEN=ghp_yourtoken' >> ~/.zshrc
   source ~/.zshrc
   ```

## Troubleshooting

- **`API ... returned HTTP 403: API rate limit exceeded`**: you've hit the 60 requests/hour limit. Wait an hour or set `GITHUB_TOKEN`.
- **`API .../releases/latest returned HTTP 404`**: the repo has no published releases, the URL is wrong, or it's a private repo and no token with access is set.
- **An expected Windows/macOS/Android file wasn't picked**: run with `--dry-run` to see the choice. Asset detection is based on file names (e.g. `win`, `x64`, `arm64`, `universal`, `.dmg`, `.apk`), so unusually named assets may be missed.
