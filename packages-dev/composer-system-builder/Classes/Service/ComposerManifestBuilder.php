<?php
declare(strict_types = 1);

namespace FES\ComposerSystemBuilder\Service;

use function Safe\json_decode;
use function Safe\json_encode;

final class ComposerManifestBuilder
{
    /** @var array{name: string, type: string, require: array<string, string>, require-dev: array<string, string>} */
    private array $baseManifest;

    private bool $enablePackagist = true;

    /** @var array<string, string> */
    private $localRepositories = [];

    private ?string $minimumStability = null;

    public function __construct(string $baseManifest)
    {
        $this->baseManifest = json_decode($baseManifest, true);
    }

    public function disablePackagistRepository(): self
    {
        $this->enablePackagist = false;

        return $this;
    }

    public function withLocalComposerRepository(string $name, string $relativeRepositoryPath): self
    {
        if (isset($this->localRepositories[$name])) {
            throw new \InvalidArgumentException(sprintf('Repository %s is already defined', $name), 1713532832);
        }
        $this->localRepositories[$name] = $relativeRepositoryPath;

        return $this;
    }

    public function withMinimumStability(string $stability): self
    {
        $this->minimumStability = $stability;

        return $this;
    }

    public function build(): string
    {
        $manifest = $this->baseManifest;
        if ($this->enablePackagist === false) {
            $manifest['repositories']['packagist.org'] = false;
        }
        foreach ($this->localRepositories as $name => $relativePath) {
            $manifest['repositories'][$name] = [
                'type' => 'composer',
                'url' => $relativePath,
            ];
        }
        if ($this->minimumStability !== null) {
            $manifest['minimum-stability'] = $this->minimumStability;
        }

        return json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }
}
