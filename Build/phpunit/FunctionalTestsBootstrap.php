<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Boilerplate for a functional test phpunit boostrap file.
 *
 * This file is loosely maintained within TYPO3 testing-framework, extensions
 * are encouraged to not use it directly, but to copy it to an own place,
 * usually in parallel to a FunctionalTests.xml file.
 *
 * This file is defined in FunctionalTests.xml and called by phpunit
 * before instantiating the test suites.
 */

use FES\ComposerTestCase\ComposerSystemTestRunner;
use FES\ComposerTestCase\WorkingDirectoryAwareJobRunner;
use PHPUnit\Framework\IsolatedTestRunnerRegistry;
use PHPUnit\Util\PHP\JobRunnerRegistry;

(static function () {
    $jobRunner = new WorkingDirectoryAwareJobRunner();
    JobRunnerRegistry::set($jobRunner);
    IsolatedTestRunnerRegistry::set(new ComposerSystemTestRunner($jobRunner));

    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');
})();
