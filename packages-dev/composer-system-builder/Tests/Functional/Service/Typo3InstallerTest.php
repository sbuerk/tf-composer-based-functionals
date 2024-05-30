<?php

namespace FES\ComposerSystemBuilder\Tests\Functional\Service;

use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use FES\ComposerSystemBuilder\Service\ComposerTestSystemFactory;
use FES\ComposerSystemBuilder\Service\SystemConfigurationFactory;
use FES\ComposerSystemBuilder\Service\Typo3Installer;
use Nimut\TestingFramework\Bootstrap\BootstrapFactory;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * This does not use the testing framework FunctionalTestCase since we don't need a database for the main test, but it
 * must run with a database available => not a "unit test" in TYPO3 speak.
 *
 * @covers \FES\ComposerSystemBuilder\Service\Typo3Installer
 */
final class Typo3InstallerTest extends TestCase
{
    private const TEST_INSTANCE_FOLDER_NAME = '_typo3-installer-test';

    private string $testInstancesFolder;

    private string $repositoryPath;

    private string $mainSystemConfigDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        // since this behaves more like a unit test, we must do more bootstrapping
        // TODO typo3/cms-core:>=11 Revisit to check if/how this must be changed
        $bootstrap = BootstrapFactory::createBootstrapInstance();
        $bootstrap->bootstrapUnitTestSystem();

        $this->testInstancesFolder = Environment::getProjectPath() . '/tests/instances';
        $this->repositoryPath = Environment::getProjectPath() . '/.mono/';
        $this->mainSystemConfigDirectory = Environment::getLegacyConfigPath();
    }

    /**
     * @test
     */
    public function setupTypo3RunsHelhumSetupCommand(): void
    {
        $testInstancePath = $this->setupTestInstancePath(self::TEST_INSTANCE_FOLDER_NAME);

        $databaseConnectionParameters = $this->getDatabaseConnectionParameters();
        $factory = new ComposerTestSystemFactory(
            $this->testInstancesFolder,
            $this->repositoryPath,
            null,
            $databaseConnectionParameters,
            new SystemConfigurationFactory()
        );
        $testSystem = $factory->buildSystem(self::TEST_INSTANCE_FOLDER_NAME, __DIR__ . '/Fixtures/composer.json', []);

        $subject = new Typo3Installer();
        $subject->setupTypo3($testSystem);

        self::assertFileExists($testInstancePath . 'public/typo3/sysext/');
        self::assertFileExists($testInstancePath . 'public/typo3conf/LocalConfiguration.php');
        self::assertFileExists($testInstancePath . 'public/typo3conf/AdditionalConfiguration.php');
        self::assertFileExists($testInstancePath . 'public/typo3conf/PackageStates.php');
    }

    private function setupTestInstancePath(string $testInstanceName): string
    {
        $testInstancePath = $this->testInstancesFolder . '/' . $testInstanceName . '/';

        if (is_dir($testInstancePath)) {
            GeneralUtility::rmdir($testInstancePath, true);
        }
        mkdir($testInstancePath);
        return $testInstancePath;
    }

    private function getDatabaseConnectionParameters(): DatabaseConnectionParameters
    {
        $localConfiguration = (require $this->mainSystemConfigDirectory . '/LocalConfiguration.php');
        self::assertIsArray($localConfiguration, 'Could not load LocalConfiguration.php');
        return DatabaseConnectionParameters::fromConnection('Default', $localConfiguration);
    }
}
