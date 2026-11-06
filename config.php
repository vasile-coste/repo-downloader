<?php
/**
 * What scraper.php downloads for each release.
 *
 * Platforms
 *   enabled   download builds for this platform
 *   arch      architectures to download, one build per entry, in this order.
 *             Known values: x64, x86, arm64, arm (32-bit), universal.
 *             [] means "the single best build, whatever the architecture".
 *   untagged  architecture to assume for files with none in their name, e.g.
 *             "azahar-windows-installer.exe" counts as x64 with 'untagged' => 'x64'.
 *             null: such files are only taken by the fallback
 *   fallback  when none of the listed architectures exist, take the best other
 *             build instead (arch-neutral/universal builds first)
 *   formats   file types to accept, most preferred first. For each architecture
 *             the best format wins; on a tie, a file naming its architecture
 *             beats an untagged one
 *   keywords  words in a file name that mark it as this platform. Needed for
 *             shared formats like .zip; formats only one platform uses
 *             (.exe, .dmg, .apk, ...) are recognised without them
 *   exclude   skip files whose name contains any of these words
 *   prefer    among builds of the same format, prefer names with these words
 *
 * Disabled platforms still recognise their files, so e.g. a macOS .zip is
 * never mistaken for something else.
 *
 * Command-line options override this file for one run, see the top of scraper.php.
 */

return [
    // The release's source code zip.
    'source' => true,

    // Download the latest prerelease when the repo has no stable release, or
    // when the latest stable release is already downloaded and a newer
    // prerelease exists.
    'prerelease' => true,

    // When no file of a release matches any platform below, download its first
    // asset as "{repo}-{version}-uncategorized.zip".
    'uncategorized' => true,

    'platforms' => [
        'windows' => [
            'enabled'  => true,
            'arch'     => ['x64', 'arm64'],
            'untagged' => 'x64',
            'fallback' => true,
            'formats'  => ['exe', 'msi', 'msix', 'appx', 'appxbundle', 'zip', '7z'],
            'keywords' => ['win', 'windows', 'win32', 'win64'],
            'exclude'  => ['portable'],
            'prefer'   => [],
        ],
        'macos' => [
            'enabled'  => true,
            'arch'     => ['universal'],
            'untagged' => 'universal',
            'fallback' => true,
            'formats'  => ['dmg', 'zip', 'pkg', 'tar.gz', 'tgz'],
            'keywords' => ['mac', 'macos', 'osx', 'darwin', 'apple'],
            'exclude'  => [],
            'prefer'   => [],
        ],
        'android' => [
            'enabled'  => true,
            'arch'     => ['arm64'],
            'untagged' => null,
            'fallback' => true,
            'formats'  => ['apk'],
            'keywords' => ['android'],
            'exclude'  => [],
            'prefer'   => ['release'],
        ],
        'linux' => [
            'enabled'  => false,
            'arch'     => ['x64'],
            'untagged' => 'x64',
            'fallback' => true,
            'formats'  => ['appimage', 'deb', 'rpm', 'flatpak', 'tar.gz', 'tar.xz', 'zip'],
            'keywords' => ['linux', 'ubuntu', 'debian'],
            'exclude'  => [],
            'prefer'   => [],
        ],
    ],
];
