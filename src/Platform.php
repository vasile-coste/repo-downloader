<?php

declare(strict_types=1);

namespace RepoDownloader;

/**
 * One platform block of config.php (windows, macos, ...) and the rules for
 * recognising and ranking its builds. See config.php for what each setting means.
 */
final class Platform
{
    /** Other names accepted for architectures in config.php and --arch. */
    public const array ARCH_ALIASES = [
        'amd64' => 'x64', 'x86_64' => 'x64', 'x86-64' => 'x64',
        'aarch64' => 'arm64', 'arm64-v8a' => 'arm64',
        'armv7' => 'arm', 'armeabi-v7a' => 'arm', 'arm32' => 'arm',
        'i386' => 'x86', 'i686' => 'x86', 'ia32' => 'x86',
    ];

    /**
     * @param list<string> $arch
     * @param list<string> $formats
     * @param list<string> $keywords
     * @param list<string> $exclude
     * @param list<string> $prefer
     */
    public function __construct(
        public readonly string $name,
        public bool $enabled = true,
        public array $arch = [],
        public readonly ?string $untagged = null,
        public readonly bool $fallback = true,
        public readonly array $formats = [],
        public readonly array $keywords = [],
        public readonly array $exclude = [],
        public readonly array $prefer = [],
    ) {
    }

    public static function fromArray(string $name, array $cfg): self
    {
        return new self(
            name: $name,
            enabled: (bool) ($cfg['enabled'] ?? true),
            arch: array_map(self::normalizeArch(...), $cfg['arch'] ?? []),
            untagged: isset($cfg['untagged']) ? self::normalizeArch($cfg['untagged']) : null,
            fallback: (bool) ($cfg['fallback'] ?? true),
            formats: array_map('strtolower', $cfg['formats'] ?? []),
            keywords: $cfg['keywords'] ?? [],
            exclude: $cfg['exclude'] ?? [],
            prefer: $cfg['prefer'] ?? [],
        );
    }

    public static function normalizeArch(string $arch): string
    {
        $arch = strtolower(trim($arch));
        return self::ARCH_ALIASES[$arch] ?? $arch;
    }

    /** File type as used in 'formats': "exe", "tar.gz", "appimage", ... */
    public static function formatOf(string $name): string
    {
        return preg_match('/\.(tar\.(?:gz|xz|bz2|zst)|[a-z0-9]+)$/i', $name, $m) ? strtolower($m[1]) : '';
    }

    /** Architecture named in a file name, if any. */
    public static function archOf(string $name): ?string
    {
        $n = strtolower($name);
        return match (true) {
            (bool) preg_match('/universal/', $n)                   => 'universal',
            (bool) preg_match('/(arm64|aarch64)/', $n)             => 'arm64',
            (bool) preg_match('/(x64|x86[_-]64|amd64|win64)/', $n) => 'x64',
            (bool) preg_match('/(armv7|armeabi|armhf|arm32)/', $n) => 'arm',
            (bool) preg_match('/(x86|i[36]86|win32|ia32)/', $n)    => 'x86',
            default                                                 => null,
        };
    }

    public function acceptsFormat(string $name): bool
    {
        return in_array(self::formatOf($name), $this->formats, true);
    }

    /** True when the name contains a keyword, not as part of a longer word ("win" not in "darwin"). */
    public function hasKeyword(string $name): bool
    {
        if (!$this->keywords) {
            return false;
        }
        $alt = implode('|', array_map(fn($w) => preg_quote($w, '/'), $this->keywords));
        return (bool) preg_match("/(?<![a-z])($alt)(?![a-z])/i", $name);
    }

    public function isExcluded(string $name): bool
    {
        foreach ($this->exclude as $word) {
            if (stripos($name, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Architecture from the file name, or the 'untagged' default. */
    public function archFor(string $name): ?string
    {
        return self::archOf($name) ?? $this->untagged;
    }

    /**
     * Sort key: preferred format first, then files that name their architecture
     * over untagged ones, then names containing a 'prefer' word.
     */
    public function rank(string $name): array
    {
        $format = array_search(self::formatOf($name), $this->formats, true);
        $prefer = count($this->prefer);
        foreach ($this->prefer as $i => $word) {
            if (stripos($name, $word) !== false) {
                $prefer = $i;
                break;
            }
        }
        return [$format === false ? PHP_INT_MAX : $format, self::archOf($name) === null ? 1 : 0, $prefer];
    }

    /**
     * Picks the best build for each configured architecture from assets of this
     * platform. When none of them exist and fallback is on, takes the best
     * arch-neutral build, else the best one.
     */
    public function pickBuilds(array $assets): array
    {
        $candidates = array_values(array_filter($assets, fn(array $a) => !$this->isExcluded($a['name'])));
        if (!$candidates) {
            return [];
        }
        usort($candidates, fn($a, $b) => $this->rank($a['name']) <=> $this->rank($b['name']));

        $picked = [];
        foreach ($this->arch as $arch) {
            foreach ($candidates as $a) {
                if ($this->archFor($a['name']) === $arch) {
                    $picked[] = $a;
                    break;
                }
            }
        }
        if ($picked || (!$this->fallback && $this->arch)) {
            return $picked;
        }
        foreach ($candidates as $a) {
            if (in_array($this->archFor($a['name']), [null, 'universal'], true)) {
                return [$a];
            }
        }
        return [$candidates[0]];
    }
}
