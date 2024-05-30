<?php

namespace FES\ComposerRepoBuilder;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Package;
use Composer\Package\RootPackageInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;

/**
 * @phpstan-type TPackageDistInfo array{type: 'zip'|'path', url: string, reference: string}
 */
class LocalRepositoryFactory
{
    private string $baseDir = '';

    private string $appsDir = '';

    private string $composerRepoDir = '';

    private Composer $composer;

    private IOInterface $io;

    private Filesystem $filesystem;

    private RootPackageInterface $rootPackage;

    private ArrayLoader $arrayLoader;

    /**
     * @var array{apps-dir?: string, repo-dir?: string, zip-packages?: list<string>, versions?: array<string, string>}
     */
    private array $pluginOptions;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $composerConfig = $composer->getConfig();
        $vendorDir = $composerConfig->get('vendor-dir');

        $baseDir = realpath(
            substr(
                $vendorDir,
                0,
                -strlen($composerConfig->get('vendor-dir', $composerConfig::RELATIVE_PATHS))
            )
        );
        if ($baseDir === false) {
            throw new \RuntimeException('Could not resolve vendor dir', 1713189841);
        }
        $this->baseDir = $baseDir;

        $this->filesystem = new Filesystem();
        $this->arrayLoader = new ArrayLoader();
        $this->composer = $composer;
        $this->rootPackage = $this->composer->getPackage();
        $this->pluginOptions = $this->rootPackage->getExtra()['fes/composer-repo'] ?? [];
        $this->composerRepoDir = $this->filesystem->normalizePath($this->baseDir . '/' . ($this->pluginOptions['repo-dir'] ?? '.mono'));
        $this->io = $io;
        $this->appsDir = $this->filesystem->normalizePath($this->baseDir . '/' . ($this->pluginOptions['apps-dir'] ?? 'apps'));
    }

    public function createComposerRepositoryFromInstalledPackages(Event $event): void
    {
        if ($this->composer !== $event->getComposer()) {
            throw new \RuntimeException('Got different Composer instance in event, this should not happen.', 1713189883);
        }

        $autoLoadGenerator = $this->composer->getAutoloadGenerator();
        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $packagesAndPath = $autoLoadGenerator->buildPackageMap($this->composer->getInstallationManager(), $this->rootPackage, $localRepo->getCanonicalPackages());
        /**
         * Aliases defined by version constraints like "dev-master as 0.9.x-dev"
         * @var list<array{package: string, version: string, alias: string, alias_normalized: string} $aliases
         */
        $aliases = $this->composer->getLocker()->getAliases();

        $this->filesystem->ensureDirectoryExists($this->composerRepoDir . '/packages/');

        $abandoned = [];
        $packages = [];
        $arrayDumper = new ArrayDumper();
        foreach ($packagesAndPath as [$package, $path]) {
            $this->io->debug(sprintf('Processing package %s', $package->getName()));
            $packageInfo = $arrayDumper->dump($package);
            if ($package->getType() !== 'metapackage') {
                unset(
                    $packageInfo['dist'],
                    $packageInfo['source'],
                    $packageInfo['installation-source'],
                    $packageInfo['notification-url'],
                );

                $createZipFile = $package !== $this->rootPackage
                    && in_array($package->getName(), $this->pluginOptions['zip-packages'] ?? []);

                if ($createZipFile) {
                    $packageDistInfo = $this->createZipFileAndReturnDistInfo($package);
                } else {
                    $packageDistInfo = $this->getDistInfoForPathPackage($package);
                }
                $packageInfo['dist'] = $packageDistInfo;
            }

            $version = $package->getPrettyVersion();
            foreach ($aliases as $alias) {
                if ($alias['package'] !== $package->getName()) {
                    continue;
                }

                $version = $packageInfo['version'] = $alias['alias'];
                $packageInfo['version_normalized'] = $alias['alias_normalized'];
            }
            if (isset($this->pluginOptions['versions'][$package->getName()])) {
                $version = $packageInfo['version'] = $this->pluginOptions['versions'][$package->getName()];
                $packageInfo['version_normalized'] = $version . '.0';
            }

            $additionalPackages = $package->getExtra()['fes/composer-repo-builder']['additional-packages'] ?? [];
            foreach ($additionalPackages as $additionalPackagePath) {
                $fullPackagePath = $this->getPackageSourceDir($package) . '/' . $additionalPackagePath;

                $additionalPackage = $this->loadPackageFromPath($fullPackagePath);
                $additionalPackageInfo = $arrayDumper->dump($additionalPackage);

                $additionalPackageInfo['dist'] = $this->getDistInfoForNotInstalledSubPackage($fullPackagePath);

                $packages[$additionalPackage->getName()][$additionalPackage->getVersion()] = $additionalPackageInfo;
            }

            $packages[$package->getName()][$version] = $packageInfo;

            foreach ($packageInfo['replace'] ?? [] as $replacedPackageName => $replacedPackageVersion) {
                // $replacedPackageVersion
                $packages[$replacedPackageName][$version] = [
                    'name' => $replacedPackageName,
                    'type' => 'metapackage',
                    'version' => $version,
                    'abandoned' => true,
                ];
                $abandoned[$replacedPackageName] = $package->getName();
            }
        }

        $packageJson = [
            'packages' => $packages,
            'abandoned' => $abandoned,
        ];

        file_put_contents($this->composerRepoDir . '/packages.json', json_encode($packageJson, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->io->info(sprintf('Created local Composer monorepo with %d packages', count($packages)));
    }

    private function loadPackageFromPath(string $fullPackagePath): Package
    {
        $manifestPath = sprintf('%s/composer.json', $fullPackagePath);
        if (!file_exists($manifestPath)) {
            throw new \RuntimeException('Could not find Composer file ' . $manifestPath, 1713347469);
        }

        $manifest = json_decode(file_get_contents($manifestPath) ?: '{}', true);
        $manifest['version'] = $this->rootPackage->getVersion();
        $manifest['version_normalized'] = $this->rootPackage->getPrettyVersion();

        return $this->arrayLoader->load($manifest);
    }

    private function createZipFileFromFolder(string $folderPath, string $targetZipFile): void
    {
        if (file_exists($targetZipFile)) {
            $this->io->debug(sprintf('ZIP file %s already exists, not creating', $targetZipFile));
            return;
        }

        $zip = new \ZipArchive();

        $ret = $zip->open($targetZipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($ret !== true) {
            throw new \RuntimeException(sprintf('Creating ZIP file %s failed with code %d', $targetZipFile, $ret), 1713532655);
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folderPath));

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isDir()) {
                continue;
            }
            $filePath = $file->getRealPath();

            $zip->addPattern('/.*/', $filePath, ['remove_path' => $folderPath]);
        }

        $zip->close();
    }

    /**
     * @return TPackageDistInfo
     */
    private function createZipFileAndReturnDistInfo(Package $package): array
    {
        $zipFilePath = sprintf('%s/%s-%s.zip', $this->composerRepoDir . '/packages/', str_replace('/', '_', $package->getName()), $package->getPrettyVersion());
        $this->createZipFileFromFolder($this->getPackageSourceDir($package), $zipFilePath);

        $packageInfo = [
            'type' => 'zip',
            'url' => $this->filesystem->findShortestPath($this->appsDir, $zipFilePath, true),
            'reference' => $package->getInstallationSource() === 'source' ? $package->getSourceReference() : $package->getDistReference(),
        ];
        return $packageInfo;
    }

    /**
     * @return TPackageDistInfo
     */
    private function getDistInfoForPathPackage(Package $package): array
    {
        return [
            'type' => 'path',
            'url' => $this->filesystem->findShortestPath($this->appsDir, $this->getPackageSourceDir($package), true),
            'reference' => $package->getInstallationSource() === 'source' ? $package->getSourceReference() : $package->getDistReference(),
        ];
    }

    /**
     * @return TPackageDistInfo
     */
    private function getDistInfoForNotInstalledSubPackage(string $packagePath): array
    {
        return [
            'type' => 'path',
            'url' => $this->filesystem->findShortestPath($this->appsDir, $packagePath, true),
            'reference' => '',
        ];
    }

    private function getPackageSourceDir(Package $package): string
    {
        return $package === $this->rootPackage ? $this->baseDir : $this->composer->getInstallationManager()->getInstallPath($package);
    }
}
