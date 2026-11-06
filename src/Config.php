<?php

declare(strict_types=1);

namespace RepoDownloader;

use RuntimeException;

/** Download settings from config.php, with command-line overrides applied. */
final class Config
{
    /** @param array<string, Platform> $platforms */
    public function __construct(
        public bool $source = true,
        public bool $uncategorized = true,
        public bool $prerelease = true,
        public array $platforms = [],
    ) {
    }

    public static function load(string $file): self
    {
        $data = is_file($file) ? require $file : null;
        if (!is_array($data) || !is_array($data['platforms'] ?? null)) {
            throw new RuntimeException("$file must return an array with a 'platforms' key");
        }
        $platforms = [];
        foreach ($data['platforms'] as $name => $cfg) {
            $platforms[$name] = Platform::fromArray($name, $cfg);
        }
        return new self(
            source: (bool) ($data['source'] ?? true),
            uncategorized: (bool) ($data['uncategorized'] ?? true),
            prerelease: (bool) ($data['prerelease'] ?? true),
            platforms: $platforms,
        );
    }

    /** Enables only the given platforms. */
    public function onlyPlatforms(array $names): void
    {
        if ($unknown = array_diff($names, array_keys($this->platforms))) {
            throw new RuntimeException(
                'Unknown platform(s): ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys($this->platforms))
            );
        }
        foreach ($this->platforms as $name => $platform) {
            $platform->enabled = in_array($name, $names, true);
        }
    }

    /** Uses the given architectures for every platform. */
    public function withArch(array $arch): void
    {
        $arch = array_map(Platform::normalizeArch(...), $arch);
        foreach ($this->platforms as $platform) {
            $platform->arch = $arch;
        }
    }

    /**
     * The platform a file belongs to: one whose keyword is in the name and that
     * accepts the format, otherwise the only platform using that format.
     * Disabled platforms count too, so their files aren't mistaken for others.
     */
    public function platformOf(string $name): ?Platform
    {
        $byFormat = [];
        foreach ($this->platforms as $platform) {
            if (!$platform->acceptsFormat($name)) {
                continue;
            }
            if ($platform->hasKeyword($name)) {
                return $platform;
            }
            $byFormat[] = $platform;
        }
        return count($byFormat) === 1 ? $byFormat[0] : null;
    }
}
