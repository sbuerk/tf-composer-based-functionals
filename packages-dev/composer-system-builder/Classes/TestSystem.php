<?php

namespace FES\ComposerSystemBuilder;

use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;

final class TestSystem
{
    private string $systemRootPath;

    private string $instanceName;

    private bool $installed = false;

    private DatabaseConnectionParameters $databaseConnectionParameters;

    public function __construct(string $systemRootPath, DatabaseConnectionParameters $dbConnection)
    {
        $systemRootPath = rtrim($systemRootPath, '/') . '/';
        $this->systemRootPath = $systemRootPath;
        $this->instanceName = basename($systemRootPath);
        $this->databaseConnectionParameters = $dbConnection;
    }

    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function setInstalled(bool $installed): void
    {
        $this->installed = $installed;
    }

    public function getSystemRootPath(): string
    {
        return $this->systemRootPath;
    }

    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    public function getDatabaseConnectionParameters(): DatabaseConnectionParameters
    {
        return $this->databaseConnectionParameters;
    }

    public function getAdminUserName(): string
    {
        return 'admin';
    }

    public function getAdminPassword(): string
    {
        return 'password';
    }

    /**
     * Includes the Core Bootstrap class and calls its first few functions
     *
     * @see \Nimut\TestingFramework\TestSystem\AbstractTestSystem::includeAndStartCoreBootstrap()
     */
    public function includeAndStartCoreBootstrap(): void
    {
        $classLoaderFile = $this->getSystemRootPath() . '/vendor/autoload.php';
        if (!file_exists($classLoaderFile)) {
            throw new \RuntimeException('Could not find test system autoload.php file', 1713168549);
        }
        $classLoader = require $classLoaderFile;

        SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_BE | SystemEnvironmentBuilder::REQUESTTYPE_CLI);
        Bootstrap::init($classLoader);
        ob_end_clean();
    }

    public function __toString()
    {
        return sprintf('TestSystem<path=%s>', $this->systemRootPath);
    }
}
