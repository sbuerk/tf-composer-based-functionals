<?php

namespace Unit;

use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FES\ComposerSystemBuilder\DatabaseConnectionParameters
 */
final class DatabaseConnectionParametersTest extends TestCase
{
    /**
     * @test
     */
    public function createFromConnectionUsesParametersFromGivenConnection(): void
    {
        $result = DatabaseConnectionParameters::fromConnection(
            'Default',
            ['DB' => ['Connections' => ['Default' => [
                'dbname' => 'the_db',
                'driver' => 'mysqli',
                'host' => 'the-host',
                'password' => 'the-password',
                'port' => 3306,
                'user' => 'the-user',
            ]]]]
        );

        self::assertSame('the_db', $result->getName());
        self::assertSame('mysqli', $result->getDriver());
        self::assertSame('the-user', $result->getUsername());
        self::assertSame('the-password', $result->getPassword());
        self::assertSame('the-host', $result->getHost());
    }
}
