<?php

namespace FES\ComposerSystemBuilder\Tests\Unit\Service;

use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use FES\ComposerSystemBuilder\Service\ComposerTestSystemFactory;
use FES\ComposerSystemBuilder\Service\SystemConfigurationFactory;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @covers \FES\ComposerSystemBuilder\Service\ComposerTestSystemFactory
 */
final class ComposerTestSystemFactoryTest extends TestCase
{
    private const TEST_INSTANCE_FOLDER_NAME = '_composer-system-builder-test';

    private string $testInstancesFolder;

    private string $repositoryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testInstancesFolder = Environment::getProjectPath() . '/tests/instances';
        $this->repositoryPath = Environment::getProjectPath() . '/.mono/';
    }

    /**
     * @test
     */
    public function buildSystemTriggersComposerInstallInTargetFolderAndReturnsTestSystemObjectWithCorrectPath(): void
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
    }

    /**
     * @test
     */
    public function buildSystemRemovesComposerLockFileAndVendorFolderWhenPresent(): void
    {
        $testInstancePath = $this->setupTestInstancePath(self::TEST_INSTANCE_FOLDER_NAME);
        mkdir($testInstancePath . '/vendor/');
        file_put_contents($testInstancePath . '/composer.lock', '{"garbage": "this will prevent Composer from working if present", "content-hash": "da39a3ee5e6b4b0d3255bfef95601890afd80709", "packages": []}');

        $subject = $this->createSubject();
        $result = $subject->buildSystem(
            self::TEST_INSTANCE_FOLDER_NAME,
            __DIR__ . '/Fixtures/composer.json',
            []
        );

        self::assertSame($testInstancePath, $result->getSystemRootPath());
        self::assertFileExists($testInstancePath . 'composer.lock');
        self::assertFileExists($testInstancePath . 'vendor/');
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

    private function createSubject(): ComposerTestSystemFactory
    {
        return new ComposerTestSystemFactory(
            $this->testInstancesFolder,
            $this->repositoryPath,
            null,
            DatabaseConnectionParameters::empty(),
            new SystemConfigurationFactory()
        );
    }
}
