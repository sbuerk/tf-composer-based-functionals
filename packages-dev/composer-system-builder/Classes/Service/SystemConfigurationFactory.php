<?php
declare(strict_types = 1);

namespace FES\ComposerSystemBuilder\Service;

use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Core\Environment;

/**
 * @phpstan-type TLocalConfigurationKey 'BE'|'DB'|'EXTCONF'|'EXTENSIONS'|'FE'|'GFX'|'MAIL'|'SYS'
 * @phpstan-type TLocalConfiguration array<TLocalConfigurationKey, array<string, mixed>>
 */
class SystemConfigurationFactory
{
    /**
     * The default config to use; {@see \Nimut\TestingFramework\TestSystem\AbstractTestSystem::$defaultConfiguration}
     * @var array<string, mixed>
     */
    private array $defaultConfiguration = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'extbase_object' => [
                        'backend' => NullBackend::class,
                    ],
                ],
            ],
            'displayErrors' => '1',
            'debugExceptionHandler' => '',
            'encryptionKey' => 'i-am-not-a-secure-encryption-key',
            'isInitialDatabaseImportDone' => true,
            'isInitialInstallationInProgress' => false,
            'setDBinit' => 'SET SESSION sql_mode = \'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY\';',
            'trustedHostsPattern' => '.*',
        ],
    ];

    /**
     * @see \Nimut\TestingFramework\TestSystem\AbstractTestSystem::setUpLocalConfiguration()
     *
     * @return TLocalConfiguration
     */
    public function getLocalConfiguration(DatabaseConnectionParameters $connectionParameters): array
    {
        $finalConfigurationArray = require Environment::getPublicPath() . '/typo3/sysext/core/Configuration/FactoryConfiguration.php';
        $finalConfigurationArray['DB'] = [
            'Connections' => [
                'Default' => $this->buildDatabaseConfiguration($connectionParameters),
            ],
        ];

        return array_merge($finalConfigurationArray, $this->defaultConfiguration);
    }

    /**
     * @return array{charset: string, dbname: string, driver: string, host: string, password: string, tableoptions: array{charset: string, collate: string}, user: string}
     */
    private function buildDatabaseConfiguration(DatabaseConnectionParameters $connectionParameters): array
    {
        return $connectionParameters->toArray();
    }
}
