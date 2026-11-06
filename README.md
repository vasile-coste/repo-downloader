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

### Prereleases

Without `--all`, the script downloads the latest prerelease instead of the latest release when:

- the repo has no stable release at all (e.g. a repo that only publishes prereleases), or
- the latest stable release is already downloaded and a newer prerelease exists.

Prerelease files go into `{version}-prerelease/` and get `-prerelease` added before the extension, e.g. `download/Lichii/build-88-prerelease/lichii-macos-arm64-prerelease.dmg`. With `--all`, prereleases are downloaded along with every other release and named the same way.

## Requirements

- PHP 8.4 or newer, with the `curl` and `json` extensions
- [Composer](https://getcomposer.org/)

Check with:

```bash
php -v
php -m | grep -E 'curl|json'
```

## Setup

1. Install the dependencies ([Guzzle](https://docs.guzzlephp.org/)):

   ```bash
   composer install
   ```

2. List the repositories in `repos.json`. Either the repo URL or its `/releases` URL works:

   ```json
   [
       "https://github.com/owner/another-repo"
   ]
   ```

3. Optional: set a GitHub token (see [GitHub token](#github-token)).

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
| `--all` | Process every release, including prereleases, instead of only the latest one. Draft releases are skipped. |
| `--limit=N` | With `--all`, only the N most recent releases. |
| `--dry-run` | Print what would be downloaded without downloading. |
| `--out=DIR` | Output directory. Default: `download/` next to the script. |
| `--repos=FILE` | Repo list to read. Default: `repos.json` next to the script. |

## Output layout

Downloads go into `download/{Repo}/{version}/`, where `{version}` is the release tag, or the publish date (`YYYY-MM-DD`) when the release has no tag:

```
download/
└── RepoName/
    └── 7/
        ├──asset-7-source.zip
        └── ├──asset-release-arm64-v8a.apk
```

Running the script again is safe: files that already exist are skipped, so only new releases or missing files are downloaded. Downloads are written to a `.part` file first and renamed when complete, so an interrupted run never leaves a broken file behind.

## GitHub token

Without a token GitHub allows 60 API requests per hour. Each repo uses about one request (more with `--all` on repos with hundreds of releases), so a token is only needed for long repo lists or private repos. With a token the limit is 5,000 per hour.

1. Create a token at <https://github.com/settings/tokens>:
   - **Fine-grained token**: "Public repositories (read-only)" is enough for public repos.
   - **Classic token**: no scopes for public repos; tick `repo` for private ones.

2. Pass it as the `GITHUB_TOKEN` environment variable.

   **macOS / Linux**

   ```bash
   # one run
   GITHUB_TOKEN=ghp_yourtoken php scraper.php

   # current terminal session
   export GITHUB_TOKEN=ghp_yourtoken

   # permanently (zsh)
   echo 'export GITHUB_TOKEN=ghp_yourtoken' >> ~/.zshrc
   source ~/.zshrc
   ```

   **Windows (PowerShell)**

   ```powershell
   # current terminal session
   $env:GITHUB_TOKEN = "ghp_yourtoken"
   php scraper.php

   # permanently (for your user; open a new terminal afterwards)
   [Environment]::SetEnvironmentVariable("GITHUB_TOKEN", "ghp_yourtoken", "User")
   ```

   **Windows (Command Prompt)**

   ```bat
   :: current terminal session
   set GITHUB_TOKEN=ghp_yourtoken
   php scraper.php

   :: permanently (for your user; open a new terminal afterwards)
   setx GITHUB_TOKEN ghp_yourtoken
   ```

   You can also set it permanently through the Windows settings: search for **"Edit environment variables for your account"**, click **New…**, and enter `GITHUB_TOKEN` as the name and your token as the value. Open a new terminal afterwards.

## Troubleshooting

- **`API ... returned HTTP 403: API rate limit exceeded`**: you've hit the 60 requests/hour limit. Wait an hour or set `GITHUB_TOKEN`.
- **`API .../releases/latest returned HTTP 404`**: the repo has no published releases, the URL is wrong, or it's a private repo and no token with access is set.
- **An expected Windows/macOS/Android file wasn't picked**: run with `--dry-run` to see the choice. Asset detection is based on file names (e.g. `win`, `x64`, `arm64`, `universal`, `.dmg`, `.apk`), so unusually named assets may be missed.
