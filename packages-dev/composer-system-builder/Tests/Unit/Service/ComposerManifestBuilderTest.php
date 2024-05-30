<?php
declare(strict_types = 1);

namespace FES\ComposerSystemBuilder\Tests\Unit\Service;

use FES\ComposerSystemBuilder\Service\ComposerManifestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FES\ComposerSystemBuilder\Service\ComposerManifestBuilder
 */
class ComposerManifestBuilderTest extends TestCase
{
    private const BASIC_COMPOSER_MANIFEST = <<<JSON
{
  "name": "test/test",
  "type": "project"
}
JSON;

    /**
     * @test
     */
    public function buildOnAnNewlyCreatedBuilderYieldsInputManifest(): void
    {
        $subject = new ComposerManifestBuilder(self::BASIC_COMPOSER_MANIFEST);

        self::assertSame(
            <<<JSON
{
    "name": "test/test",
    "type": "project"
}
JSON,
            $subject->build()
        );
    }

    /**
     * @test
     */
    public function disablePackagistAddsRepositoryEntryThatDisablesPackagist(): void
    {
        $subject = new ComposerManifestBuilder(self::BASIC_COMPOSER_MANIFEST);
        $subject->disablePackagistRepository();

        self::assertSame(
            <<<JSON
{
    "name": "test/test",
    "type": "project",
    "repositories": {
        "packagist.org": false
    }
}
JSON,
            $subject->build()
        );
    }

    /**
     * @test
     */
    public function localRepositoriesAreAddedAfterPackagistOrgEntry(): void
    {
        $subject = new ComposerManifestBuilder(self::BASIC_COMPOSER_MANIFEST);
        $subject->disablePackagistRepository()
            ->withLocalComposerRepository('foo', '../foo/')
            ->withLocalComposerRepository('bar', '../baz/');

        self::assertSame(
            <<<JSON
{
    "name": "test/test",
    "type": "project",
    "repositories": {
        "packagist.org": false,
        "foo": {
            "type": "composer",
            "url": "../foo/"
        },
        "bar": {
            "type": "composer",
            "url": "../baz/"
        }
    }
}
JSON,
            $subject->build()
        );
    }
}
