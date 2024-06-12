<?php

namespace FES\ComposerTestCase;

use PHPUnit\Util\PHP\DefaultJobRunner;
use PHPUnit\Util\PHP\Job;
use PHPUnit\Util\PHP\JobRunner;
use PHPUnit\Util\PHP\PhpProcessException;
use PHPUnit\Util\PHP\Result;
use SebastianBergmann\Environment\Runtime;

/**
 * Copied from {@see DefaultJobRunner} and adjusted to use the specified working directory.
 */
final class WorkingDirectoryAwareJobRunner implements JobRunner
{
    private string $workingDirectory;

    public function setWorkingDirectory(string $workingDirectory): void
    {
        $this->workingDirectory = $workingDirectory;
    }
    /**
     * @throws PhpProcessException
     */
    public function run(Job $job): Result
    {
        $temporaryFile = null;

        if ($job->hasInput()) {
            $temporaryFile = tempnam(sys_get_temp_dir(), 'phpunit_');

            if ($temporaryFile === false ||
                file_put_contents($temporaryFile, $job->code()) === false) {
                // @codeCoverageIgnoreStart
                throw new PhpProcessException(
                    'Unable to write temporary file',
                );
                // @codeCoverageIgnoreEnd
            }

            $job = new Job(
                $job->input(),
                $job->phpSettings(),
                $job->environmentVariables(),
                $job->arguments(),
                null,
                $job->redirectErrors(),
            );
        }

        assert($temporaryFile !== '');

        return $this->runProcess($job, $temporaryFile);
    }

    /**
     * @psalm-param ?non-empty-string $temporaryFile
     *
     * @throws PhpProcessException
     */
    private function runProcess(Job $job, ?string $temporaryFile): Result
    {
        $environmentVariables = null;

        if ($job->hasEnvironmentVariables()) {
            $environmentVariables = $_SERVER ?? [];

            unset($environmentVariables['argv'], $environmentVariables['argc']);

            $environmentVariables = array_merge($environmentVariables, $job->environmentVariables());

            foreach ($environmentVariables as $key => $value) {
                if (is_array($value)) {
                    unset($environmentVariables[$key]);
                }
            }

            unset($key, $value);

        }

        $pipeSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        if ($job->redirectErrors()) {
            $pipeSpec[2] = ['redirect', 1];
        }

        $process = proc_open(
            $this->buildCommand($job, $temporaryFile),
            $pipeSpec,
            $pipes,
            $this->workingDirectory,
            $environmentVariables,
        );

        if (!is_resource($process)) {
            // @codeCoverageIgnoreStart
            throw new PhpProcessException(
                'Unable to spawn worker process',
            );
            // @codeCoverageIgnoreEnd
        }

        fwrite($pipes[0], $job->code());
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';

        if (isset($pipes[1])) {
            $stdout = stream_get_contents($pipes[1]);

            fclose($pipes[1]);
        }

        if (isset($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[2]);
        }

        proc_close($process);

        if ($temporaryFile !== null) {
            unlink($temporaryFile);
        }

        return new Result($stdout, $stderr);
    }

    /**
     * @psalm-return non-empty-list<string>
     */
    private function buildCommand(Job $job, ?string $file): array
    {
        $runtime     = new Runtime;
        $command     = [PHP_BINARY];
        $phpSettings = $job->phpSettings();

        if ($runtime->hasPCOV()) {
            $phpSettings = array_merge(
                $phpSettings,
                $runtime->getCurrentSettings(
                    array_keys(ini_get_all('pcov')),
                ),
            );
        } elseif ($runtime->hasXdebug()) {
            $phpSettings = array_merge(
                $phpSettings,
                $runtime->getCurrentSettings(
                    array_keys(ini_get_all('xdebug')),
                ),
            );
        }

        $command = array_merge($command, $this->settingsToParameters($phpSettings));

        if (PHP_SAPI === 'phpdbg') {
            $command[] = '-qrr';

            if ($file === null) {
                $command[] = 's=';
            }
        }

        if ($file !== null) {
            $command[] = '-f';
            $command[] = $file;
        }

        if ($job->hasArguments()) {
            if ($file === null) {
                $command[] = '--';
            }

            foreach ($job->arguments() as $argument) {
                $command[] = trim($argument);
            }
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    private function settingsToParameters(array $settings): array
    {
        $buffer = [];

        foreach ($settings as $setting) {
            $buffer[] = '-d';
            $buffer[] = $setting;
        }

        return $buffer;
    }
}