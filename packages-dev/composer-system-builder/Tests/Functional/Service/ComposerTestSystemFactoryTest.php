<?php
declare(strict_types = 1);

namespace FES\ComposerSystemBuilder\Tests\Functional\Service;

use Carbon\Carbon;
use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use FES\ComposerSystemBuilder\Service\ComposerTestSystemFactory;
use FES\ComposerSystemBuilder\Service\SystemConfigurationFactory;
use Nimut\TestingFramework\Bootstrap\BootstrapFactory;
use PHPUnit\Framework\TestCase;
use function Safe\file_get_contents;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * These tests run in a functional test environment since they need proper database connection parameters.
 *
 * @covers \FES\ComposerSystemBuilder\Service\ComposerTestSystemFactory
 *
 * @see \FES\ComposerSystemBuilder\Tests\Unit\Service\ComposerTestSystemFactoryTest for more basic tests that do not
 *      install TYPO3
 */
final class ComposerTestSystemFactoryTest extends TestCase
{
    private const TEST_INSTANCE_FOLDER_NAME = '_composer-system-builder-test';

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

    protected function tearDown(): void
    {
        parent::tearDown();

        Carbon::setTestNow();
    }

    /**
     * @test
     */
    public function buildSystemDoesNotCreatePackageStatesWhileInstallingTypo3System(): void
    {
        $testInstancePath = $this->setupTestInstancePath(self::TEST_INSTANCE_FOLDER_NAME);

        $subject = $this->createSubject();
        $result = $subject->buildSystem(
            self::TEST_INSTANCE_FOLDER_NAME,
            __DIR__ . '/Fixtures/composer.json',
            []
        );

        self::assertSame($testInstancePath, $result->getSystemRootPath());
        self::assertFileExists($testInstancePath . 'composer.lock');
        self::assertFileExists($testInstancePath . 'vendor/');
        self::assertFileDoesNotExist($testInstancePath . 'public/typo3conf/PackageStates.php');
    }

    /**
     * @test
     */
    public function buildSystemCreatesFileWithInstallTimestampWhenRunningTest(): void
    {
        $testInstancePath = $this->setupTestInstancePath(self::TEST_INSTANCE_FOLDER_NAME);

        Carbon::setTestNow(Carbon::createFromTimestamp(1713256200));

        $subject = $this->createSubject();
        $result = $subject->buildSystem(
            self::TEST_INSTANCE_FOLDER_NAME,
            __DIR__ . '/Fixtures/composer.json',
            []
        );

        self::assertSame($testInstancePath, $result->getSystemRootPath());
        self::assertFileExists($testInstancePath . ComposerTestSystemFactory::SETUP_TIME_FILE);
        self::assertSame('1713256200', file_get_contents($testInstancePath . ComposerTestSystemFactory::SETUP_TIME_FILE));
    }

    /**
     * @test
     */
    public function buildSystemDoesNotReinstallWhenTimestampIsLessThan300SecondsOld(): void
    {
        $testInstancePath = $this->setupTestInstancePath(self::TEST_INSTANCE_FOLDER_NAME);

        // first setup

        $initialTime = 1713256200;
        Carbon::setTestNow(Carbon::createFromTimestamp($initialTime));

        $subject = $this->createSubject();
        $result = $subject->buildSystem(
            self::TEST_INSTANCE_FOLDER_NAME,
            __DIR__ . '/Fixtures/composer.json',
            []
        );

        self::assertSame($testInstancePath, $result->getSystemRootPath());
        self::assertFileExists($testInstancePath . ComposerTestSystemFactory::SETUP_TIME_FILE);
        self::assertSame((string)$initialTime, file_get_contents($testInstancePath . ComposerTestSystemFactory::SETUP_TIME_FILE));

        // advance time by 300 seconds, reinstall and check if the file is still unchanged

        Carbon::setTestNow(Carbon::createFromTimestamp($initialTime + 300));

        $result = $subject->buildSystem(
            self::TEST_INSTANCE_FOLDER_NAME,
            __DIR__ . '/Fixtures/composer.json',
            []
        );

        self::assertSame($testInstancePath, $result->getSystemRootPath());
        self::assertFileExists($testInstancePath . ComposerTestSystemFactory::SETUP_TIME_FILE);
        self::assertSame((string)$initialTime, file_get_contents($testInstancePath . ComposerTestSystemFactory::SETUP_TIME_FILE));
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

    private function createSubject(): ComposerTestSystemFactory
    {
        return new ComposerTestSystemFactory(
            $this->testInstancesFolder,
            $this->repositoryPath,
            null,
            $this->getDatabaseConnectionParameters(),
            new SystemConfigurationFactory()
        );
    }
}
