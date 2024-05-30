<?php

namespace FES\ComposerSystemBuilder\Service;

use Carbon\Carbon;
use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use FES\ComposerSystemBuilder\TestSystem;
use function Safe\file_get_contents;
use function Safe\file_put_contents;
use function Safe\unlink;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Ensures a composerized test system is in the desired state.
 *
 * This works in close cooperation with helhum/composer-mono and therefore (as of now) requires all test systems to be
 * in the same folder.
 *
 * @phpstan-import-type TLocalConfiguration from SystemConfigurationFactory
 */
final class ComposerTestSystemFactory
{
    private string $instancesBaseDir;

    private string $composerRepositoryPath;

    private ?string $testToken;

    private DatabaseConnectionParameters $projectDatabaseConnectionParameters;

    private SystemConfigurationFactory $configurationFactory;

    private Filesystem $filesystem;

    public const SETUP_TIME_FILE = 'setup_time';

    private const MAXIMUM_SYSTEM_AGE = 300;

    /**
     * @param string|null $testToken Optional token that is used for creating multiple parallel instances
     */
    public function __construct(
        string $instancesBaseDir,
        string $composerRepositoryPath,
        ?string $testToken,
        DatabaseConnectionParameters $connectionParameters,
        SystemConfigurationFactory $configurationFactory
    ) {
        $this->instancesBaseDir = rtrim($instancesBaseDir, '/');
        $this->composerRepositoryPath = $composerRepositoryPath;
        $this->testToken = $testToken;
        $this->projectDatabaseConnectionParameters = $connectionParameters;
        $this->configurationFactory = $configurationFactory;
        $this->filesystem = new Filesystem();
    }

    /**
     * Builds a test system by running "composer install" and placing LocalConfiguration.php/AdditionalConfiguration.php
     * files
     *
     * @param TLocalConfiguration $localConfiguration
     */
    public function buildSystem(string $name, string $composerManifestOrManifestFile, array $localConfiguration, bool $allowParallelUsage = false): TestSystem
    {
        $system = $this->getTestSystem($name, $allowParallelUsage);
        if (!is_dir($system->getSystemRootPath())) {
            $this->filesystem->mkdir($system->getSystemRootPath(), 0755);
        }

        // TODO php:>=8.0 use json_validate here instead
        if ($composerManifestOrManifestFile[0] === '{') {
            $composerManifest = $composerManifestOrManifestFile;
        } else {
            $composerManifest = file_get_contents($composerManifestOrManifestFile);
        }
        $manifestBuilder = new ComposerManifestBuilder($composerManifest);
        $manifestBuilder->disablePackagistRepository()
            ->withMinimumStability('dev')
            ->withLocalComposerRepository(
                'mono',
                $this->filesystem->makePathRelative($this->composerRepositoryPath, $system->getSystemRootPath())
            );
        file_put_contents($system->getSystemRootPath() . '/composer.json', $manifestBuilder->build());

        $setupTimeFile = $system->getSystemRootPath() . '/' . self::SETUP_TIME_FILE;
        if (file_exists($setupTimeFile)) {
            $installTimestamp = file_get_contents($setupTimeFile);
            $oldestAcceptableInstallTime = Carbon::now()->subSeconds(self::MAXIMUM_SYSTEM_AGE);
            if (Carbon::createFromTimestamp($installTimestamp)->greaterThanOrEqualTo($oldestAcceptableInstallTime)) {
                return $this->getSystemIfExists($name, $allowParallelUsage);
            }
        }

        $lockFilePath = $system->getSystemRootPath() . 'composer.lock';
        if (file_exists($lockFilePath)) {
            unlink($lockFilePath);
        }

        $this->filesystem->mkdir($system->getSystemRootPath() . 'public/typo3conf/', 0755);
        // TODO typo3/cms-core:>=12 change the location of these files
        $localConfigurationContents = $this->getLocalConfigurationContents($system->getDatabaseConnectionParameters());
        file_put_contents(
            $system->getSystemRootPath() . '/public/typo3conf/LocalConfiguration.php',
            $localConfigurationContents
        );
        file_put_contents(
            $system->getSystemRootPath() . '/public/typo3conf/AdditionalConfiguration.php',
            $this->getAdditionalConfigurationContents($localConfiguration)
        );

        $process = new Process(
            ['/usr/bin/env', 'composer', 'install', '--no-progress', '--no-cache', '-vvv'],
            $system->getSystemRootPath(),
            CommandEnvironment::getEnvironmentForTestSystemCommand($system)
        );
        $returnCode = $process->run();
        if ($returnCode !== 0) {
            throw new \RuntimeException(
                sprintf("Composer installation in test system %s failed with return code %d: %s\n\n%s", $system->getSystemRootPath(), $returnCode, $process->getOutput(), $process->getErrorOutput()),
                1700757647
            );
        }

        file_put_contents(
            $setupTimeFile,
            Carbon::now()->getTimestamp()
        );

        return $system;
    }

    /**
     * Returns an instance of an already built system
     */
    public function getSystemIfExists(string $name, bool $allowParallelUsage = false): TestSystem
    {
        // TODO validate if the system was already installed
        $testSystem = $this->getTestSystem($name, $allowParallelUsage);
        $testSystem->setInstalled(true);
        return $testSystem;
    }

    private function getLocalConfigurationContents(DatabaseConnectionParameters $connectionParameters): string
    {
        return sprintf(
            <<<FILE
<?php

return %s;
FILE,
            var_export($this->configurationFactory->getLocalConfiguration($connectionParameters), true)
        );
    }

    /**
     * @param TLocalConfiguration $localConfiguration
     */
    private function getAdditionalConfigurationContents(array $localConfiguration): string
    {
        return sprintf(
            <<<FILE
<?php

\TYPO3\CMS\Core\Utility\ArrayUtility::mergeRecursiveWithOverrule(
    \$GLOBALS['TYPO3_CONF_VARS'],
    %s
);
FILE,
            var_export($localConfiguration, true)
        );
    }

    private function getTestSystem(string $name, bool $allowParallelUsage): TestSystem
    {
        $connectionParameters = $this->projectDatabaseConnectionParameters
            ->withInstanceIdentifier($name);

        if ($allowParallelUsage && $this->testToken) {
            $connectionParameters = $connectionParameters->withInstanceIdentifier($this->testToken);
            $name = "{$name}_{$this->testToken}";
        }
        return new TestSystem(
            $this->instancesBaseDir . '/' . $name . '/',
            $connectionParameters
        );
    }
}
