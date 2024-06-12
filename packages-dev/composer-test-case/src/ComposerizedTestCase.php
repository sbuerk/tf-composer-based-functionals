<?php

namespace FES\ComposerTestCase;

use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use FES\ComposerSystemBuilder\TestSystem;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class ComposerizedTestCase extends FunctionalTestCase
{
    protected ?TestSystem $testSystem = null;

    abstract public function getComposerManifest(): string;

    abstract public function getTestSystemName(): string;

    public function getConfigurationToUseInTestInstance(): array
    {
        return $this->configurationToUseInTestInstance;
    }

    protected function setUp(): void
    {
        // intentionally not calling parent::setUp() because we do not want
        // TODO maybe change this?

        // ------

        $this->testSystem = $this->getTestSystem();

        // this is our replacement for FunctionalTestCase::setUp()
        $this->testSystem->includeAndStartCoreBootstrap();

        // TODO perform database cleanup
    }

    /**
     * Creates an instance of TestSystem. The system must be set up already, so this should only be called within the
     * spawned process.
     *
     * TODO this should not require DatabaseConnectionParameters, since that is only required for setting up the system.
     */
    private function getTestSystem(): TestSystem
    {
        $testSystemPath = getcwd();

        return new TestSystem($testSystemPath, DatabaseConnectionParameters::empty());
    }
}