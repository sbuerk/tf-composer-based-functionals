<?php
declare(strict_types = 1);

namespace FES\ComposerTestCase;

use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use FES\ComposerSystemBuilder\Service\ComposerTestSystemFactory;
use FES\ComposerSystemBuilder\Service\SystemConfigurationFactory;
use FES\ComposerSystemBuilder\Service\Typo3Installer;
use FES\ComposerSystemBuilder\TestSystem;
use PHPUnit\Framework\ErrorTestCase;
use PHPUnit\Framework\SkippedTestCase;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\WarningTestCase;
use function Safe\tempnam;
use SebastianBergmann\Template\Template;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;

/**
 * Trait for running tests with Composer-based test systems.
 *
 * This overwrites parts of {@see TestCase} to run a test in an external, Composer-based test system.
 *
 * To work, this requires a patch for {@see TestCase}.
 */
trait ComposerizedSystemTestRun
{
    protected ?TestSystem $testSystem;

    /**
     * Must be static so it is kept between different test runs; concept borrowed from nimut/TF.
     */
    protected static bool $isFirstTest = true;

    public function run(TestResult $result = null): TestResult
    {
        if ($result === null) {
            $result = $this->createResult();
        }

        if ($this->runInSeparateProcess()) {
            // this is the minimal replacement for setUp() in nimut/TF's FunctionalTestCase
            $this->setTypo3Context();
            $this->includeAndStartCoreBootstrap();

            if ($this instanceof ComposerizedLegacyTestCase) {
                $this->composerManifest = (new LegacyComposerManifestFactory())
                    ->createComposerManifestForLegacyFunctionalTestCase($this);
                $this->testSystemName = $this->deriveTestSystemName();
                $this->preserveGlobalState = false;
            }

            $this->testSystem = $this->createTestSystem();

            $this->runTestInComposerSystem($result);
        } else {
            $this->testSystem = $this->getTestSystem();

            // this is our replacement for FunctionalTestCase::setUp()
            $this->testSystem->includeAndStartCoreBootstrap();

            $legacyTestSystem = new LegacyFunctionalTestSystem('not-used');
            $legacyTestSystem->cleanDatabase();

            $result->run($this);
        }

        $this->result = null;
        self::$isFirstTest = false;

        return $result;
    }

    private function createTestSystem(): TestSystem
    {
        $composerTestSystemFactory = $this->getTestSystemFactory();
        $allowParallelUsage = (string)getenv('TEST_TOKEN') !== '';
        $testSystem = $composerTestSystemFactory
            ->buildSystem($this->testSystemName, $this->composerManifest, $this->configurationToUseInTestInstance, $allowParallelUsage);

        if (!$testSystem->isInstalled()) {
            (new Typo3Installer())->setupTypo3($testSystem);
        }

        return $testSystem;
    }

    private function getTestSystemFactory(): ComposerTestSystemFactory
    {
        $localConfiguration = require Environment::getLegacyConfigPath() . '/LocalConfiguration.php';
        self::assertIsArray($localConfiguration, 'Could not load LocalConfiguration.php');
        $databaseParameters = DatabaseConnectionParameters::fromConnection('Default', $localConfiguration);

        $instancesBaseDir = $this->getInstancesBaseDir();

        return new ComposerTestSystemFactory(
            $instancesBaseDir,
            $this->getRepositoryDir(),
            getenv('TEST_TOKEN') ?: null,
            $databaseParameters,
            new SystemConfigurationFactory()
        );
    }

    /**
     * Creates an instance of TestSystem. The system must be set up already, so this should only be called within the
     * spawned process.
     *
     * TODO this should not require DatabaseConnectionParameters, since that is only required for setting up the system.
     */
    private function getTestSystem(): TestSystem
    {
        $testSystemPath = getenv('TEST_SYSTEM_PATH');
        if (!is_string($testSystemPath) || !file_exists($testSystemPath)) {
            throw new \RuntimeException('Could not get test system path from environment', 1713272089);
        }

        return new TestSystem($testSystemPath, DatabaseConnectionParameters::empty());
    }

    /**
     * Adjusted version of {@see TestCase::run()} for running tests in a completely isolated Composer project.
     * The project is set up in [project dir]/tests/instances/…/ and available in {@see self::$testSystem}
     *
     * This is basically what PHPUnit does if process isolation is enabled. Key differences are:
     *
     * 1. we use a separate working directory {@see DefaultPhpProcessWithWorkdir}
     * 2. we need to disable global state preservation (which is enabled by default), since that would trigger loading
     *    the Composer autoloader of the main TYPO3 system (and more issues, see below in the if-branch)
     *
     * This is only the code for running in a separate process within an external system, see {@see run()} for the
     * if/else switch that decides if we need to branch out to the external system or just run the test.
     *
     * @see TestCase::run() in PHPUnit 9.6.16 for the original code
     */
    protected function runTestInComposerSystem(TestResult $result): TestResult
    {
        if ($this->testSystem === null) {
            throw new \RuntimeException('Test system not set', 1713169278);
        }

        $runEntireClass = $this->getRunClassInSeparateProcess() && !$this->runTestInSeparateProcess;

        $class = new \ReflectionClass($this);

        $templateBasePath = __DIR__ . '/../../../vendor/phpunit/phpunit/src/';
        if ($runEntireClass) {
            $template = new Template(
                $templateBasePath . 'Util/PHP/Template/TestCaseClass.tpl',
            );
        } else {
            $template = new Template(
                $templateBasePath . 'Util/PHP/Template/TestCaseMethod.tpl',
            );
        }

        if ($this->preserveGlobalState) {
            /**
             * This is currently not supported due to (at least) the following issues:
             *
             * 1. vendor/autoload.php is part of $includedFiles, therefore loading the autoloader of the "child" system
             *    breaks for e.g. typo3/class-alias-loaders
             * 2. the TYPO3_PATH_* variables are set from vendor/typo3/autoload-include.php, so
             *    a) they must be overridden or unset in the process command environment
             *    b) the file must be excluded from $includedFiles
             *
             * If we need global state preservation, we should have a second look here.
             */
            throw new \RuntimeException(
                'Preserving the global state with Composer-based systems is not supported right now. ' .
                'See the code for a comment that details why, and what would be needed to change that.',
                1713166312
            );
        }
        $constants = '';

        if (!empty($GLOBALS['__PHPUNIT_BOOTSTRAP'])) {
            $bootstrapFile = $GLOBALS['__PHPUNIT_BOOTSTRAP'];
            if ($bootstrapFile[0] !== '/') {
                $bootstrapFile = realpath($this->testSystem->getSystemRootPath() . '/' . $GLOBALS['__PHPUNIT_BOOTSTRAP']) ?: null;
                if ($bootstrapFile === null) {
                    throw new \RuntimeException('Could not get absolute path to bootstrap file ' . $GLOBALS['__PHPUNIT_BOOTSTRAP'], 1713189473);
                }
            }
            $globals = '$GLOBALS[\'__PHPUNIT_BOOTSTRAP\'] = ' . var_export($bootstrapFile, true) . ";\n";
        } else {
            $globals = '';
        }
        // required to correctly set PATH_thisScript and prevent "Unable to determine path to entry script"
        // TODO typo3/cms-core:>=11 Check if this is still required on v11; it might be possible to completely
        //      throw this method away as long as we disable $preserveGlobalState in that case
        $globals .= '$_SERVER[\'argv\'][0] = \'' . $this->testSystem->getSystemRootPath() . 'public/typo3/index.php\';' . "\n";

        $includedFiles = '';
        $iniSettings = '';

        $coverage = $result->getCollectCodeCoverageInformation() ? 'true' : 'false';
        $isStrictAboutTestsThatDoNotTestAnything = $result->isStrictAboutTestsThatDoNotTestAnything() ? 'true' : 'false';
        $isStrictAboutOutputDuringTests = $result->isStrictAboutOutputDuringTests() ? 'true' : 'false';
        $enforcesTimeLimit = $result->enforcesTimeLimit() ? 'true' : 'false';
        $isStrictAboutTodoAnnotatedTests = $result->isStrictAboutTodoAnnotatedTests() ? 'true' : 'false';
        $isStrictAboutResourceUsageDuringSmallTests = $result->isStrictAboutResourceUsageDuringSmallTests() ? 'true' : 'false';

        // must be modified to use the autoloader of the built child system
        $composerAutoload = '"' . $this->testSystem->getSystemRootPath() . '/vendor/autoload.php"';

        if (defined('__PHPUNIT_PHAR__')) {
            $phar = var_export(__PHPUNIT_PHAR__, true);
        } else {
            $phar = '\'\'';
        }

        $codeCoverage = $result->getCodeCoverage();
        $codeCoverageFilter = null;
        $cachesStaticAnalysis = 'false';
        $codeCoverageCacheDirectory = null;
        $driverMethod = 'forLineCoverage';

        if ($codeCoverage) {
            $codeCoverageFilter = $codeCoverage->filter();

            if ($codeCoverage->collectsBranchAndPathCoverage()) {
                $driverMethod = 'forLineAndPathCoverage';
            }

            if ($codeCoverage->cachesStaticAnalysis()) {
                $cachesStaticAnalysis = 'true';
                $codeCoverageCacheDirectory = $codeCoverage->cacheDirectory();
            }
        }

        $data = var_export(serialize($this->getProvidedData()), true);
        $dataName = var_export($this->getDataSetAsString(false), true);
        $dependencyInput = var_export(serialize($this->getDependencyInput()), true);
        $includePath = var_export(get_include_path(), true);
        $codeCoverageFilter = var_export(serialize($codeCoverageFilter), true);
        $codeCoverageCacheDirectory = var_export(serialize($codeCoverageCacheDirectory), true);
        // must do these fixes because TestCaseMethod.tpl has unserialize('{data}') in it, and we can't break BC
        // the lines above used to use addcslashes() rather than var_export(), which breaks null byte escape sequences
        $data = "'." . $data . ".'";
        $dataName = "'.(" . $dataName . ").'";
        $dependencyInput = "'." . $dependencyInput . ".'";
        $includePath = "'." . $includePath . ".'";
        $codeCoverageFilter = "'." . $codeCoverageFilter . ".'";
        $codeCoverageCacheDirectory = "'." . $codeCoverageCacheDirectory . ".'";

        $configurationFilePath = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? '';
        if ($configurationFilePath !== '' && $configurationFilePath[0] !== '/') {
            $configurationFilePath = realpath(getcwd() . '/' . $configurationFilePath) ?: null;
            if ($configurationFilePath === null) {
                throw new \RuntimeException('Could not get absolute path to configuration file', 1713189486);
            }
        }
        $processResultFile = tempnam(sys_get_temp_dir(), 'phpunit_');

        $var = [
            'composerAutoload' => $composerAutoload,
            'phar' => $phar,
            'filename' => $class->getFileName(),
            'className' => $class->getName(),
            'collectCodeCoverageInformation' => $coverage,
            'cachesStaticAnalysis' => $cachesStaticAnalysis,
            'codeCoverageCacheDirectory' => $codeCoverageCacheDirectory,
            'driverMethod' => $driverMethod,
            'data' => $data,
            'dataName' => $dataName,
            'dependencyInput' => $dependencyInput,
            'constants' => $constants,
            'globals' => $globals,
            'include_path' => $includePath,
            'included_files' => $includedFiles,
            'iniSettings' => $iniSettings,
            'isStrictAboutTestsThatDoNotTestAnything' => $isStrictAboutTestsThatDoNotTestAnything,
            'isStrictAboutOutputDuringTests' => $isStrictAboutOutputDuringTests,
            'enforcesTimeLimit' => $enforcesTimeLimit,
            'isStrictAboutTodoAnnotatedTests' => $isStrictAboutTodoAnnotatedTests,
            'isStrictAboutResourceUsageDuringSmallTests' => $isStrictAboutResourceUsageDuringSmallTests,
            'codeCoverageFilter' => $codeCoverageFilter,
            'configurationFilePath' => $configurationFilePath,
            'name' => $this->getName(false),
            'processResultFile' => $processResultFile,
        ];

        if (!$runEntireClass) {
            $var['methodName'] = $this->getName(false);
        }

        $template->setVar($var);

        $php = new DefaultPhpProcessWithWorkdir($this->testSystem);
        $php->setWorkingDirectory($this->testSystem->getSystemRootPath());
        $script = $template->render();
        $php->runTestJob($script, $this, $result, $processResultFile);

        return $result;
    }

    /**
     * Minimum bootstrap required for things like {@see Environment::getPublicPath()} to work.
     * This runs in the main Composer system.
     *
     * @see \Nimut\TestingFramework\TestSystem\AbstractTestSystem::includeAndStartCoreBootstrap for the source of this
     * TODO typo3/cms-core:>=11 check if this needs adjustments
     */
    protected function includeAndStartCoreBootstrap(): void
    {
        /**
         * TYPO3_version is set in {@see SystemEnvironmentBuilder::defineBaseConstants()},
         * so this is an indication that {@see SystemEnvironmentBuilder::run()} has run
         */
        if (defined('TYPO3_version')) {
            return;
        }

        $classLoader = require dirname(__FILE__, 4) . '/vendor/autoload.php';

        SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_BE | SystemEnvironmentBuilder::REQUESTTYPE_CLI);
        Bootstrap::init($classLoader);
        ob_end_clean();
    }

    /**
     * Defines some constants and sets the environment variable TYPO3_CONTEXT
     *
     * @see \Nimut\TestingFramework\TestSystem\AbstractTestSystem::setTypo3Context for the source of this
     * TODO typo3/cms-core:>=11 check if this needs adjustments
     */
    protected function setTypo3Context(): void
    {
        define('TYPO3_MODE', 'BE');
        define('TYPO3_cliMode', true);
        // Disable TYPO3_DLOG
        define('TYPO3_DLOG', false);

        // modifying TYPO3_PATH_* was removed here, since we run the test in an external process anyways

        // this prevents "unable to determine path to entry script"
        $_SERVER['argv'][0] = 'index.php';
    }

    private function getInstancesBaseDir(): string
    {
        $instancesBaseDir = dirname(__DIR__, 3) . '/tests/instances/';
        self::assertFileExists($instancesBaseDir);

        return $instancesBaseDir;
    }

    private function getRepositoryDir(): string
    {
        $instancesBaseDir = dirname(__DIR__, 3) . '/.mono/';
        self::assertFileExists($instancesBaseDir);

        return $instancesBaseDir;
    }
}
