<?php

declare(strict_types=1);

namespace SBUERK\TfBasicsProject\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DummyTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        $this->testExtensionsToLoad = array_merge(
            $this->testExtensionsToLoad,
            [
                'internal/custom-command',
                // 'internal/custom_middleware',
            ]
        );

        // no call to parent because we do not want to use the FunctionalTestCase bootstrap by nimut/TF or typo3/TF
        if (!defined('ORIGINAL_ROOT')) {
            $this->markTestSkipped('Functional tests must be called through phpunit on CLI');
        }
    }

    #[Test]
    public function customCommandExtensionLoaded(): void
    {
        self::assertTrue(ExtensionManagementUtility::isLoaded('custom_command'));
    }

    #[Test]
    public function customMiddlewareExtensionLoaded(): void
    {
        self::assertTrue(ExtensionManagementUtility::isLoaded('custom_middleware'));
    }
}