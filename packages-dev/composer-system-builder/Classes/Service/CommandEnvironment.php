<?php

namespace FES\ComposerSystemBuilder\Service;

use FES\ComposerSystemBuilder\TestSystem;

/**
 * Utility class for generating required environment variables for running commands
 */
final class CommandEnvironment
{
    /**
     * @return array<string, scalar>
     */
    public static function getEnvironmentForTestSystemCommand(TestSystem $system): array
    {
        return [
            // these paths must be set here for functional tests to work correctly - even though we operate on a
            // full-blown TYPO3 system - since typo3/autoload-include.php sets the paths for functional tests and these
            // settings are inherited in functional tests and would disturb TYPO3s auto-detection mechanism
            // TODO typo3/cms-core:>=11 check if/how we need to change this
            'TYPO3_PATH_COMPOSER_ROOT' => $system->getSystemRootPath(),
            'TYPO3_PATH_APP' => $system->getSystemRootPath() . 'public',
            // TYPO3_PATH_ROOT should normally not be set manually, at least on v9,
            // see https://github.com/TYPO3-Console/TYPO3-Console/issues/928, but we still need to set it here
            'TYPO3_PATH_ROOT' => $system->getSystemRootPath() . 'public',
            'TYPO3_PATH_WEB' => $system->getSystemRootPath() . 'public',
            'TEST_SYSTEM_PATH' => $system->getSystemRootPath(),
        ];
    }
}
