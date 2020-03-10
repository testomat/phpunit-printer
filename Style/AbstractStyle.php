<?php

declare(strict_types=1);

/**
 * Copyright (c) 2020 Daniel Bannert
 *
 * For the full copyright and license information, please view
 * the LICENSE.md file that was distributed with this source code.
 *
 * @see https://github.com/testomat/phpunit
 */

namespace Testomat\PHPUnit\Printer\Style;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Framework\Warning;
use PHPUnit\Runner\BaseTestRunner;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\Runner\Version;
use ReflectionObject;
use SebastianBergmann\Environment\Runtime;
use SebastianBergmann\Timer\Timer as SBTimer;
use Testomat\PHPUnit\Common\Configuration\Configuration;
use Testomat\PHPUnit\Common\Configuration\PHPUnitConfiguration;
use Testomat\PHPUnit\Common\Terminal\Highlighter;
use Testomat\PHPUnit\Common\Terminal\Terminal;
use Testomat\PHPUnit\Common\Timer;
use Testomat\PHPUnit\Common\Util;
use Testomat\PHPUnit\Printer\Contract\Style as StyleContract;
use Testomat\PHPUnit\Printer\Contract\TestResult;
use Testomat\PHPUnit\Printer\Exception\Renderer as ExceptionRenderer;
use Testomat\PHPUnit\Printer\State;
use Testomat\PHPUnit\Printer\TestResult\Content;
use Testomat\TerminalColour\Formatter;
use Testomat\TerminalColour\Style;
use Throwable;

abstract class AbstractStyle implements StyleContract
{
    protected const TYPES = [
        BaseTestRunner::STATUS_FAILURE,
        BaseTestRunner::STATUS_WARNING,
        BaseTestRunner::STATUS_RISKY,
        BaseTestRunner::STATUS_INCOMPLETE,
        BaseTestRunner::STATUS_SKIPPED,
        BaseTestRunner::STATUS_PASSED,
    ];

    protected const STYLES = [
        BaseTestRunner::STATUS_UNKNOWN => ['cyan', null, ['bold']],
        BaseTestRunner::STATUS_PASSED => ['green', null, ['bold']],
        BaseTestRunner::STATUS_SKIPPED => ['white', null, ['bold']],
        BaseTestRunner::STATUS_INCOMPLETE => ['cyan', null, ['bold']],
        BaseTestRunner::STATUS_FAILURE => ['red', null, ['bold']],
        BaseTestRunner::STATUS_ERROR => ['light_red', null, ['bold']],
        BaseTestRunner::STATUS_RISKY => ['magenta', null, ['bold']],
        BaseTestRunner::STATUS_WARNING => ['yellow', null, ['bold']],
        Content::RUNS => ['blue', null, ['bold']],
    ];

    /** @var \Testomat\PHPUnit\Common\Terminal\Terminal */
    protected $output;

    /** @var \Testomat\PHPUnit\Common\Terminal\TerminalSection */
    protected $footer;

    /** @var \Testomat\TerminalColour\Contract\WrappableFormatter */
    protected $colour;

    /** @var \Testomat\PHPUnit\Printer\Exception\Renderer */
    protected $exceptionRenderer;

    /** @var bool */
    protected static $firstLine = true;

    /** @var \Testomat\PHPUnit\Common\Configuration\Configuration */
    protected $configuration;

    /** @var \Testomat\PHPUnit\Common\Configuration\PHPUnitConfiguration */
    protected $phpunitConfiguration;

    /** @var int */
    protected $numberOfColumns;

    /** @var bool */
    private $verbose;

    public function __construct(
        Terminal $terminal,
        string $colors,
        int $numberOfColumns,
        bool $verbose,
        Configuration $configuration,
        PHPUnitConfiguration $phpunitConfiguration
    ) {
        if ($numberOfColumns < 16) {
            $numberOfColumns = 16;
        }

        $this->output = $terminal;
        $this->numberOfColumns = $numberOfColumns;
        $this->verbose = $verbose;
        $this->configuration = $configuration;
        $this->phpunitConfiguration = $phpunitConfiguration;

        $this->footer = $this->output->section();

        if ($colors === 'always') {
            $enableColor = true;
        } elseif ($colors === 'auto') {
            $enableColor = $this->output->hasColorSupport();
        } else {
            $enableColor = false;
        }

        $styles = [];

        foreach (static::STYLES as $name => $style) {
            $styles[Content::MAPPER[$name]] = new Style(...$style);
        }

        $this->colour = new Formatter($enableColor, $styles, $this->output->getStream());
        $this->exceptionRenderer = new ExceptionRenderer($this->colour, new Highlighter($this->colour, $this->configuration->isUtf8()));

        if ($this->configuration->showErrorOn() === Configuration::SHOW_ERROR_ON_TEST && $this->configuration->getType() === Configuration::TYPE_EXPANDED) {
            $this->exceptionRenderer->disableHeader();
        }

        $this->exceptionRenderer->ignoreFilesIn($this->configuration->getIgnoredFiles()->toArray());
    }

    /**
     * {@inheritdoc}
     */
    public function addWarning(State $state, TestCase $test, Warning $exception, float $time): void
    {
        if ($stopOnWarning = $this->phpunitConfiguration->stopOnWarning()) {
            $this->writeCurrentRecap($state);

            if ($stopOnWarning) {
                $this->stop($state, 'You configured PHPUnit to stop on a warning.');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addIncompleteTest(State $state, TestCase $test, Throwable $t, float $time): void
    {
        if ($stopOnIncomplete = $this->phpunitConfiguration->stopOnIncomplete()) {
            $this->writeCurrentRecap($state);

            if ($stopOnIncomplete) {
                $this->stop($state, 'You configured PHPUnit to stop on a incomplete test.');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addRiskyTest(State $state, TestCase $test, Throwable $t, float $time): void
    {
        if ($stopOnRisky = $this->phpunitConfiguration->stopOnRisky()) {
            $this->writeCurrentRecap($state);

            if ($stopOnRisky) {
                $this->stop($state, 'You configured PHPUnit to stop on a risky test.');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addSkippedTest(State $state, TestCase $test, Throwable $t, float $time): void
    {
        if ($stopOnSkipped = $this->phpunitConfiguration->stopOnSkipped()) {
            $this->writeCurrentRecap($state);

            if ($stopOnSkipped) {
                $this->stop($state, 'You configured PHPUnit to stop on a skipped test.');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function startTestSuite(State $state, TestSuite $suite): void
    {
    }

    /**
     * {@inheritdoc}
     */
    public function endTest(State $state, TestCase $test, float $time): void
    {
    }

    public function renderError(Throwable $throwable): string
    {
        $exceptionRenderer = $this->exceptionRenderer;

        if ($this->phpunitConfiguration->stopOnError()) {
            $exceptionRenderer->disableHeader();
        }

        return $exceptionRenderer->render($throwable);
    }

    /**
     * {@inheritdoc}
     */
    public function addError(State $state, TestCase $testCase, Throwable $throwable, float $time): void
    {
        $stopOnError = $this->phpunitConfiguration->stopOnError();

        if ($stopOnError || ($this->configuration->showErrorOn() === Configuration::SHOW_ERROR_ON_TEST && $this->configuration->getType() === Configuration::TYPE_EXPANDED)) {
            $this->writeCurrentRecap($state);

            $this->output->writeln(\PHP_EOL . $state->getLastTestCase()->failureContent);

            if ($stopOnError) {
                $this->stop($state, 'You configured PHPUnit to stop on a error.');
            }
        }
    }

    public function renderFailure(AssertionFailedError $error): string
    {
        $reflector = new ReflectionObject($error);

        if ($reflector->hasProperty('message')) {
            $message = trim((string) preg_replace("/\r|\n/", ' ', $error->getMessage()));
            $property = $reflector->getProperty('message');
            $property->setAccessible(true);
            $property->setValue($error, $message);
        }

        $exceptionRenderer = $this->exceptionRenderer;

        if ($this->phpunitConfiguration->stopOnFailure()) {
            $exceptionRenderer->disableHeader();
        }

        return $exceptionRenderer->render($error);
    }

    /**
     * {@inheritdoc}
     */
    public function addFailure(State $state, TestCase $testCase, AssertionFailedError $error, float $time): void
    {
        $stopOnFailure = $this->phpunitConfiguration->stopOnFailure();

        if ($stopOnFailure || ($this->configuration->showErrorOn() === Configuration::SHOW_ERROR_ON_TEST && $this->configuration->getType() === Configuration::TYPE_EXPANDED)) {
            $this->writeCurrentRecap($state);

            $this->output->writeln(\PHP_EOL . $state->getLastTestCase()->failureContent);

            if ($stopOnFailure) {
                $this->stop($state, 'You configured PHPUnit to stop on a failure.');
            }
        }
    }

    /**
     * Writes the final recap.
     */
    public function writeRecap(State $state, int $numAssertions): void
    {
        $this->writeSummary($numAssertions);

        if ($this->configuration->isSpeedTrapActive()) {
            $this->writeSlowTests(array_filter($state->suiteTests, static function (TestResult $testResult) {
                return $testResult->isSlow;
            }));
        }

        if ($this->configuration->isOverAssertiveActive()) {
            $this->writeOverAssertiveTests(array_filter($state->suiteTests, static function (TestResult $testResult) {
                return $testResult->isOverAssertive;
            }));
        }

        $showErrors = true;

        if ($this->configuration->getType() === Configuration::TYPE_EXPANDED && $this->configuration->showErrorOn() !== Configuration::SHOW_ERROR_ON_END) {
            $showErrors = false;
        }

        $errors = array_filter($state->suiteTests, static function (TestResult $testResult) {
            return $testResult->type === BaseTestRunner::STATUS_FAILURE || $testResult->type === BaseTestRunner::STATUS_ERROR;
        });

        $countErrors = \count($errors);

        if ($showErrors && $countErrors !== 0) {
            $this->output->writeln($this->colour->format(
                \PHP_EOL . sprintf('<%s>Recorded %s error%s:</>', Content::MAPPER[BaseTestRunner::STATUS_FAILURE], $countErrors, $countErrors === 1 ? '' : 's', ) . \PHP_EOL
            ));

            foreach ($errors as $error) {
                $this->output->writeln($error->failureContent . \PHP_EOL);
            }
        } else {
            $this->output->writeln('');
        }

        $this->writeDifferentResultsOnConfigurationValidationErrors();
    }

    public function writeEmptyTestMessage(State $state): void
    {
        if (\count($state->testCaseTests) === 0) {
            $this->output->writeln($this->colour->format(\Safe\sprintf('%s <fg=yellow>No tests executed!</>%s', $this->phpunitConfiguration->isConfigurationV8() ? '' : \PHP_EOL, \PHP_EOL)));

            $this->writeDifferentResultsOnConfigurationValidationErrors();
        }
    }

    public function writePHPUnitHeader(): void
    {
        $runtime = new Runtime();

        $this->output->writeln(Version::getVersionString() . \PHP_EOL);
        $this->output->writeln($this->colour->format('<effects=bold>Runtime:</>                ' . $runtime->getNameWithVersionAndCodeCoverageDriver()));
        $this->output->writeln($this->colour->format('<effects=bold>PHPUnit Configuration:</>  ' . $this->phpunitConfiguration->getFilename()));
        $this->output->writeln($this->colour->format('<effects=bold>Testomat Configuration:</> ' . $this->configuration->getFilename()));

        if ($this->phpunitConfiguration->getExecutionOrder() === TestSuiteSorter::ORDER_RANDOMIZED) {
            $this->output->writeln($this->colour->format('<effects=bold>Random seed:</>            ' . (string) Util::getPHPUnitTestRunnerArguments()['randomOrderSeed']));
        }

        if ($this->numberOfColumns === 16) {
            $this->output->writeln($this->colour->format(' <warning>FAIL</>  Less than 16 columns requested, number of columns set to 16.') . \PHP_EOL);
        }

        if ($runtime->discardsComments()) {
            $this->output->writeln($this->colour->format(' <warning>WARN</>  opcache.save_comments=0 set; annotations will not work.') . \PHP_EOL);
        }

        $phpunitConfigurationHasErrors = $this->phpunitConfiguration->hasValidationErrors();

        if ($phpunitConfigurationHasErrors) {
            $this->output->writeln($this->colour->format(\PHP_EOL . ' <warning>WARN</>  The PHPUnit configuration file did not pass validation!'));
            $this->output->writeln('       The following problems have been detected:' . \PHP_EOL);

            $this->writeConfigurationErrors($this->phpunitConfiguration->getValidationErrors());
        }

        $configurationHasErrors = $this->configuration->hasValidationErrors();

        if ($configurationHasErrors) {
            $this->output->writeln($this->colour->format(\PHP_EOL . ' <warning>WARN</>  The Testomat configuration file did not pass validation!'));
            $this->output->writeln('       The following problems have been detected:' . \PHP_EOL);

            $this->writeConfigurationErrors($this->configuration->getValidationErrors());
        }

        $this->output->writeln($this->colour->format(\Safe\sprintf('%s<effects=bold>Test Cases:</>%s', $configurationHasErrors || $phpunitConfigurationHasErrors ? '' : \PHP_EOL, \PHP_EOL)));
    }

    /**
     * @return array<int, string>
     */
    protected function calculateTests(State $state): array
    {
        $tests = [];

        foreach (self::TYPES as $type) {
            if ($countTests = $state->countTestsInTestSuiteBy($type)) {
                $tests[] = $this->colour->format(\Safe\sprintf('<%s>%s : %s</>', Content::MAPPER[$type], $countTests, Content::MAPPER[$type]));
            }
        }

        $pending = $state->suiteTotalTests - $state->testSuiteTestsCount();

        if ($pending) {
            $tests[] = $this->colour->format(\Safe\sprintf('<%s>%s : pending</>', Content::MAPPER[Content::RUNS], $pending));
        }

        return $tests;
    }

    /**
     * @throws \Safe\Exceptions\StringsException
     */
    protected function writeSummary(int $assertions): void
    {
        $this->output->writeln(
            $this->colour->format(\Safe\sprintf('<fg=default;effects=bold>Assertions made: </><fg=default>%s</>', (string) $assertions))
        );

        $this->output->writeln(
            $this->colour->format(\Safe\sprintf('<fg=default;effects=bold>Time:            </><fg=default>%s</>', Timer::secondsToTimeString(SBTimer::stop())))
        );
        $this->output->writeln(
            $this->colour->format(\Safe\sprintf('<fg=default;effects=bold>Memory:          </><fg=default>%s</>', SBTimer::bytesToString(memory_get_peak_usage(true))))
        );
    }

    /**
     * Summery on a stopOn... option call.
     */
    protected function stop(State $state, string $reason): void
    {
        $this->output->writeln($this->colour->format(\Safe\sprintf(\PHP_EOL . '<fg=light_blue>Note: %s</>' . \PHP_EOL, $reason)));

        $tests = $this->calculateTests($state);

        if (\count($tests) !== 0) {
            $this->output->writeln($this->colour->format(\Safe\sprintf('<fg=default;effects=bold>Tests:           </>%s', implode(', ', $tests))));
        }

        $this->writeSummary(\count($state->suiteTests));

        exit(1);
    }

    private function writeOverAssertiveTests(array $assertions): void
    {
        $countAssertions = \count($assertions);

        if ($countAssertions !== 0) {
            $reportLength = $this->configuration->getOverAssertiveReportLength();

            $this->output->writeln($this->colour->format(
                \PHP_EOL . sprintf(
                    '<fg=yellow;effects=bold>Recorded %s test%s that has more assertion than %s (only the latest %s tests are shown):</>',
                    $countAssertions,
                    $countAssertions === 1 ? '' : 's',
                    $this->configuration->getOverAssertiveAlertThreshold(),
                    $reportLength
                ) . \PHP_EOL
            ));

            // Display test with the most assertions first
            arsort($assertions);

            foreach (\array_slice($assertions, 0, $reportLength, true) as $test) {
                $this->output->writeln($this->colour->format(
                    sprintf(
                        '<fg=default> %s assertion%s in %s::%s <effects=bold>(expected < %s)</></>',
                        $test->assertions,
                        $test->assertions >= 2 ? 's' : '',
                        $test->class,
                        $test->method,
                        $test->threshold
                    )
                ));
            }
        }
    }

    /**
     * @param array<int, object> $slow
     */
    private function writeSlowTests(array $slow): void
    {
        $reportLength = $this->configuration->getSpeedTrapReportLength();
        $countSlowTests = \count($slow);

        if ($countSlowTests !== 0) {
            $this->output->writeln($this->colour->format(
                \PHP_EOL . sprintf(
                    '<fg=yellow;effects=bold>Recorded %s slow test%s (only the slowest %s tests are shown):</>',
                    $countSlowTests,
                    $countSlowTests === 1 ? '' : 's',
                    $reportLength
                ) . \PHP_EOL
            ));

            // Display slowest tests first
            arsort($slow);

            foreach (\array_slice($slow, 0, $reportLength, true) as $test) {
                $this->output->writeln($this->colour->format(
                    sprintf(
                        '<fg=default> [%s] to run %s::%s <effects=bold>(expected < %s)</></>',
                        Timer::secondsToTimeString($test->time),
                        $test->class,
                        $test->method,
                        Timer::secondsToTimeString($test->speedTrapThreshold / 1000)
                    )
                ));
            }
        }
    }

    /**
     * @param array<int, array<int, string>> $configurationErrors
     */
    private function writeConfigurationErrors(array $configurationErrors): void
    {
        foreach ($configurationErrors as $line => $errors) {
            $this->output->writeln($this->colour->format(\Safe\sprintf(' <warning>⚠</>   <fg=white>Line %s</>  %s%s', $line, \count($errors) > 1 ? '• ' : '', $errors[0])));

            unset($errors[0]);

            foreach ($errors as $error) {
                $this->output->writeln("              • {$error}");
            }

            $this->output->writeln('');
        }
    }

    private function writeDifferentResultsOnConfigurationValidationErrors(): void
    {
        if ($this->phpunitConfiguration->hasValidationErrors() || $this->configuration->hasValidationErrors()) {
            $this->output->writeln($this->colour->format('<warning>Test results may not be as expected. Some configuration validation errors were found.</>' . \PHP_EOL));
        }
    }
}
