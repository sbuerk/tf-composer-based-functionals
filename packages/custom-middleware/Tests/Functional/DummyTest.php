<?php

declare(strict_types=1);

namespace Internal\CustomMiddleware\Tests\Functional;

use FES\ComposerTestCase\ComposerizedTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DummyTest extends ComposerizedTestCase
{
    public function getComposerManifest(): string
    {
        return __DIR__ . '/composer.json';
    }

    public function getTestSystemName(): string
    {
        return 'custom-middleware';
    }

    #[Test]
    public function customMiddlewareExtensionLoaded(): void
    {
        self::assertTrue(ExtensionManagementUtility::isLoaded('custom_middleware'));
    }

    #[Test]
    public function customCommandExtensionNotLoaded(): void
    {
        self::assertFalse(ExtensionManagementUtility::isLoaded('custom_command'));
    }
}