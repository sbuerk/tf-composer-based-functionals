<?php

namespace FES\ComposerSystemBuilder\Service;

use Doctrine\DBAL\DriverManager;
use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use FES\ComposerSystemBuilder\TestSystem;
use Symfony\Component\Process\Process;

/**
 * Runs installer scripts from helhum/typo3-console to set up a TYPO3 system.
 */
final class Typo3Installer
{
    public function setupTypo3(TestSystem $system): void
    {
        $this->ensureDatabaseExists($system->getDatabaseConnectionParameters());

        $setupCommandParameters = [
            '--skip-integrity-check', // required because we supplied a LocalConfiguration.php file
            '--no-interaction',
            '--site-setup-type=none',
            '--web-server-config=none',
            '--admin-user-name=' . $system->getAdminUserName(),
            '--admin-password=' . $system->getAdminPassword(),
            '-vvv',
        ];
        $process = new Process(
            ['./vendor/bin/typo3cms', 'install:setup', ...$setupCommandParameters],
            $system->getSystemRootPath(),
            CommandEnvironment::getEnvironmentForTestSystemCommand($system)
        );

        $returnCode = $process->run();
        if ($returnCode !== 0) {
            throw new \RuntimeException(sprintf(
                "TYPO3 Setup in test system %s failed with code %d: %s\n\n%s",
                $system->getSystemRootPath(),
                $returnCode,
                $process->getOutput(),
                $process->getErrorOutput()
            ), 1711384075);
        }
    }

    private function ensureDatabaseExists(DatabaseConnectionParameters $connectionParameters): void
    {
        $connectionParametersArray = $connectionParameters->toArray();
        unset($connectionParametersArray['dbname']);
        $connection = DriverManager::getConnection($connectionParametersArray);
        $schemaManager = $connection->getSchemaManager();
        $databases = $schemaManager->listDatabases();

        $databaseName = $connectionParameters->getName();
        if (in_array($databaseName, $databases)) {
            $schemaManager->dropDatabase($databaseName);
        }

        $result = $connection->executeStatement(sprintf(
            'CREATE DATABASE `%s` CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $databaseName
        ));
    }
}
