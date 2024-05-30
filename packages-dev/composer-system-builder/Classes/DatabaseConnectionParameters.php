<?php

namespace FES\ComposerSystemBuilder;

use FES\ComposerSystemBuilder\Service\SystemConfigurationFactory;

/**
 * @phpstan-import-type TLocalConfiguration from SystemConfigurationFactory
 */
class DatabaseConnectionParameters
{
    private string $driver;
    private string $host;
    private string $username;
    private string $password;
    private string $name;

    public function __construct(string $driver, string $host, string $username, string $password, string $name)
    {
        $this->driver = $driver;
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->name = $name;
    }

    /**
     * @param TLocalConfiguration|null $TYPO3_CONF_VARS
     */
    public static function fromConnection(string $connectionName, ?array $TYPO3_CONF_VARS): self
    {
        $TYPO3_CONF_VARS ??= $GLOBALS['TYPO3_CONF_VARS'];

        /** @var array{driver?: string, host?: string, user?: string, password?: string, dbname?: string} $databaseConnections */
        $databaseConnections = $TYPO3_CONF_VARS['DB']['Connections'][$connectionName] ?? [];

        return new self(
            $databaseConnections['driver'] ?? '',
            $databaseConnections['host'] ?? '',
            $databaseConnections['user'] ?? '',
            $databaseConnections['password'] ?? '',
            $databaseConnections['dbname'] ?? '',
        );
    }

    public static function empty(): self
    {
        return new self('', '', '', '', '');
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array{charset: string, dbname: string, driver: string, host: string, password: string, tableoptions: array{charset: string, collate: string}, user: string}
     */
    public function toArray(): array
    {
        return [
            'charset' => 'utf8mb4',
            'dbname' => $this->getName(),
            'driver' => $this->getDriver(),
            'host' => $this->getHost(),
            'password' => $this->getPassword(),
            'tableoptions' => [
                'charset' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci',
            ],
            'user' => $this->getUsername(),
        ];
    }

    public function withInstanceIdentifier(string $instanceIdentifier): self
    {
        $new = clone $this;
        $new->name = sprintf('%s_%s', $new->name, str_replace('-', '_', $instanceIdentifier));
        if (strlen($new->name) >= 64) {
            throw new \RuntimeException(
                sprintf('Database name %s is too long. Time to implement some code to shorten it.', $new->name),
                1711384748
            );
        }

        return $new;
    }
}
