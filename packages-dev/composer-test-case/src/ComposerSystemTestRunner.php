<?php

namespace FES\ComposerTestCase;

use ErrorException;
use Exception;
use FES\ComposerSystemBuilder\DatabaseConnectionParameters;
use FES\ComposerSystemBuilder\Service\ComposerTestSystemFactory;
use FES\ComposerSystemBuilder\Service\SystemConfigurationFactory;
use FES\ComposerSystemBuilder\Service\Typo3Installer;
use FES\ComposerSystemBuilder\TestSystem;
use PHPUnit\Event\Code\TestMethodBuilder;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Facade;
use PHPUnit\Event\NoPreviousThrowableException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\IsolatedTestRunner;
use PHPUnit\Framework\ProcessIsolationException;
use PHPUnit\Framework\SeparateProcessTestRunner;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\CodeCoverage;
use PHPUnit\TestRunner\TestResult\PassedTests;
use PHPUnit\TextUI\Configuration\Registry as ConfigurationRegistry;
use PHPUnit\Util\GlobalState;
use PHPUnit\Util\PHP\Job;
use PHPUnit\Util\PHP\JobRunnerRegistry;
use PHPUnit\Util\PHP\PhpProcessException;
use SebastianBergmann\CodeCoverage\StaticAnalysisCacheNotConfiguredException;
use SebastianBergmann\Template\InvalidArgumentException;
use SebastianBergmann\Template\Template;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Testbase;
use function assert;
use function defined;
use function file_exists;
use function file_get_contents;
use function get_include_path;
use function hrtime;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function unserialize;
use function var_export;

/**
 * Copied from {@see IsolatedTestRunner} and adjusted to perform the Composer setup.
 */
final readonly class ComposerSystemTestRunner implements IsolatedTestRunner
{
    public function __construct(private WorkingDirectoryAwareJobRunner $jobRunner)
    {
    }

    public function run(TestCase $test, bool $runEntireClass, bool $preserveGlobalState): void
    {
        // since this class is set as the responsible runner very early in the bootstrap, we must check here if the
        // test to run _really_ needs a Composer setup
        if (!$test instanceof ComposerizedTestCase) {
            (new SeparateProcessTestRunner())->run($test, $runEntireClass, $preserveGlobalState);
        }

        $testSystem = $this->createTestSystem($test);
        $this->jobRunner->setWorkingDirectory($testSystem->getSystemRootPath());

        if ($preserveGlobalState === true) {
            throw new \RuntimeException('Global state preserving not supported');
        }

        $this->doRun($test, $runEntireClass, $preserveGlobalState);
    }

    private function createTestSystem(ComposerizedTestCase $testCase): TestSystem
    {
        $composerTestSystemFactory = $this->getTestSystemFactory();
        $allowParallelUsage = (string)getenv('TEST_TOKEN') !== '';
        $testSystem = $composerTestSystemFactory
            ->buildSystem(
                $testCase->getTestSystemName(),
                $testCase->getComposerManifest(),
                $testCase->getConfigurationToUseInTestInstance(),
                $allowParallelUsage
            );

        if (!$testSystem->isInstalled()) {
            (new Typo3Installer())->setupTypo3($testSystem);
        }

        return $testSystem;
    }

    private function getTestSystemFactory(): ComposerTestSystemFactory
    {
        $testbase = new Testbase();
        $databaseConfiguration = $testbase->getOriginalDatabaseSettingsFromEnvironmentOrLocalConfiguration();
        $databaseParameters = DatabaseConnectionParameters::fromConnection('Default', ['DB' => $databaseConfiguration]);

        $instancesBaseDir = $this->getInstancesBaseDir();

        return new ComposerTestSystemFactory(
            $instancesBaseDir,
            $this->getRepositoryDir(),
            getenv('TEST_TOKEN') ?: null,
            $databaseParameters,
            new SystemConfigurationFactory()
        );
    }

    private function getInstancesBaseDir(): string
    {
        // TODO make this path configurable
        $instancesBaseDir = dirname(__DIR__, 3) . '/Tests/Instances/';
        assert(file_exists($instancesBaseDir), 'Instances base directory does not exist');

        return $instancesBaseDir;
    }

    private function getRepositoryDir(): string
    {
        // TODO make this path configurable
        $instancesBaseDir = dirname(__DIR__, 3) . '/.mono/';
        assert(file_exists($instancesBaseDir), 'Repository directory does not exist');

        return $instancesBaseDir;
    }

    /**
     * @throws \PHPUnit\Runner\Exception
     * @throws \PHPUnit\Util\Exception
     * @throws \Exception
     * @throws InvalidArgumentException
     * @throws NoPreviousThrowableException
     * @throws ProcessIsolationException
     * @throws StaticAnalysisCacheNotConfiguredException
     */
    private function doRun(TestCase $test, bool $runEntireClass, bool $preserveGlobalState): void
    {
        $class = new \ReflectionClass($test);

        // NOTE: the templates are copied from PHPUnit
        if ($runEntireClass) {
            $template = new Template(
                __DIR__ . '/../templates/class.tpl',
            );
        } else {
            $template = new Template(
                __DIR__ . '/../templates/method.tpl',
            );
        }

        $bootstrap     = '';
        $constants     = '';
        $globals       = '';
        $includedFiles = '';
        $iniSettings   = '';

        if (ConfigurationRegistry::get()->hasBootstrap()) {
            $bootstrap = ConfigurationRegistry::get()->bootstrap();
        }

        if ($preserveGlobalState) {
            $constants     = GlobalState::getConstantsAsString();
            $globals       = GlobalState::getGlobalsAsString();
            $includedFiles = GlobalState::getIncludedFilesAsString();
            $iniSettings   = GlobalState::getIniSettingsAsString();
        }

        $coverage         = CodeCoverage::instance()->isActive() ? 'true' : 'false';
        $linesToBeIgnored = var_export(CodeCoverage::instance()->linesToBeIgnored(), true);

        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            $composerAutoload = var_export(PHPUNIT_COMPOSER_INSTALL, true);
        } else {
            $composerAutoload = '\'\'';
        }

        if (defined('__PHPUNIT_PHAR__')) {
            $phar = var_export(__PHPUNIT_PHAR__, true);
        } else {
            $phar = '\'\'';
        }

        $data            = var_export(serialize($test->providedData()), true);
        $dataName        = var_export($test->dataName(), true);
        $dependencyInput = var_export(serialize($test->dependencyInput()), true);
        $includePath     = var_export(get_include_path(), true);
        // must do these fixes because TestCaseMethod.tpl has unserialize('{data}') in it, and we can't break BC
        // the lines above used to use addcslashes() rather than var_export(), which breaks null byte escape sequences
        $data                    = "'." . $data . ".'";
        $dataName                = "'.(" . $dataName . ").'";
        $dependencyInput         = "'." . $dependencyInput . ".'";
        $includePath             = "'." . $includePath . ".'";
        $offset                  = hrtime();
        $serializedConfiguration = $this->saveConfigurationForChildProcess();
        $processResultFile       = tempnam(sys_get_temp_dir(), 'phpunit_');

        $var = [
            'bootstrap'                      => $bootstrap,
            'composerAutoload'               => $composerAutoload,
            'phar'                           => $phar,
            'filename'                       => $class->getFileName(),
            'className'                      => $class->getName(),
            'collectCodeCoverageInformation' => $coverage,
            'linesToBeIgnored'               => $linesToBeIgnored,
            'data'                           => $data,
            'dataName'                       => $dataName,
            'dependencyInput'                => $dependencyInput,
            'constants'                      => $constants,
            'globals'                        => $globals,
            'include_path'                   => $includePath,
            'included_files'                 => $includedFiles,
            'iniSettings'                    => $iniSettings,
            'name'                           => $test->name(),
            'offsetSeconds'                  => $offset[0],
            'offsetNanoseconds'              => $offset[1],
            'serializedConfiguration'        => $serializedConfiguration,
            'processResultFile'              => $processResultFile,
        ];

        if (!$runEntireClass) {
            $var['methodName'] = $test->name();
        }

        $template->setVar($var);

        $code = $template->render();

        assert($code !== '');

        $this->runTestJob($code, $test, $processResultFile);

        @unlink($serializedConfiguration);
    }

    /**
     * @psalm-param non-empty-string $code
     *
     * @throws Exception
     * @throws NoPreviousThrowableException
     * @throws PhpProcessException
     */
    private function runTestJob(string $code, Test $test, string $processResultFile): void
    {
        $result = JobRunnerRegistry::run(new Job($code));

        $processResult = '';

        if (file_exists($processResultFile)) {
            $processResult = file_get_contents($processResultFile);

            @unlink($processResultFile);
        }

        $this->processChildResult(
            $test,
            $processResult,
            $result->stderr(),
        );
    }

    /**
     * @throws Exception
     * @throws NoPreviousThrowableException
     */
    private function processChildResult(Test $test, string $stdout, string $stderr): void
    {
        if (!empty($stderr)) {
            $exception = new Exception(trim($stderr));

            assert($test instanceof TestCase);

            Facade::emitter()->testErrored(
                TestMethodBuilder::fromTestCase($test),
                ThrowableBuilder::from($exception),
            );

            return;
        }

        set_error_handler(
        /**
         * @throws ErrorException
         */
            static function (int $errno, string $errstr, string $errfile, int $errline): never
            {
                throw new ErrorException($errstr, $errno, $errno, $errfile, $errline);
            },
        );

        try {
            $childResult = unserialize($stdout);

            restore_error_handler();

            if ($childResult === false) {
                $exception = new AssertionFailedError('Test was run in child process and ended unexpectedly');

                assert($test instanceof TestCase);

                Facade::emitter()->testErrored(
                    TestMethodBuilder::fromTestCase($test),
                    ThrowableBuilder::from($exception),
                );

                Facade::emitter()->testFinished(
                    TestMethodBuilder::fromTestCase($test),
                    0,
                );
            }
        } catch (ErrorException $e) {
            restore_error_handler();

            $childResult = false;

            $exception = new Exception(trim($stdout), 0, $e);

            assert($test instanceof TestCase);

            Facade::emitter()->testErrored(
                TestMethodBuilder::fromTestCase($test),
                ThrowableBuilder::from($exception),
            );
        }

        if ($childResult !== false) {
            if (!empty($childResult['output'])) {
                $output = $childResult['output'];
            }

            Facade::instance()->forward($childResult['events']);
            PassedTests::instance()->import($childResult['passedTests']);

            assert($test instanceof TestCase);

            $test->setResult($childResult['testResult']);
            $test->addToAssertionCount($childResult['numAssertions']);

            if (CodeCoverage::instance()->isActive() && $childResult['codeCoverage'] instanceof \SebastianBergmann\CodeCoverage\CodeCoverage) {
                CodeCoverage::instance()->codeCoverage()->merge(
                    $childResult['codeCoverage'],
                );
            }
        }

        if (!empty($output)) {
            print $output;
        }
    }

    /**
     * @throws ProcessIsolationException
     */
    private function saveConfigurationForChildProcess(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phpunit_');

        if ($path === false) {
            throw new ProcessIsolationException;
        }

        if (!ConfigurationRegistry::saveTo($path)) {
            throw new ProcessIsolationException;
        }

        return $path;
    }
}