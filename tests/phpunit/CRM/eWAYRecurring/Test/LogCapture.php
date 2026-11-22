<?php

/**
 * Records what the code under test writes to Civi::log(), so a test can assert
 * on it. Call captureLog() in the test and releaseLog() in tearDown().
 *
 * A PHPUnit mock of the PSR-3 interface is used so that the test does not
 * depend on which version of psr/log is installed.
 */
trait CRM_eWAYRecurring_Test_LogCapture {

  /**
   * Each: ['level', 'message', 'context'].
   *
   * @var array[]
   */
  private $logRecords = [];

  /**
   * @var \Civi\Core\LogManager|null
   */
  private $logManager;

  /**
   * The log channels as they were before captureLog().
   *
   * @var array|null
   */
  private $originalLogChannels;

  private function captureLog(): void {
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
      $logger->method($level)->willReturnCallback(function ($message, $context = []) use ($level) {
        $this->logRecords[] = ['level' => $level, 'message' => (string) $message, 'context' => (array) $context];
      });
    }

    $this->logManager = \Civi::container()->get('psr_log_manager');
    $channels = new ReflectionProperty($this->logManager, 'channels');
    $channels->setAccessible(TRUE);
    $this->originalLogChannels = $channels->getValue($this->logManager);
    $channels->setValue($this->logManager, ['default' => $logger] + $this->originalLogChannels);
    $this->logRecords = [];
  }

  private function releaseLog(): void {
    if ($this->logManager !== NULL) {
      $channels = new ReflectionProperty($this->logManager, 'channels');
      $channels->setAccessible(TRUE);
      $channels->setValue($this->logManager, $this->originalLogChannels);
      $this->logManager = NULL;
      $this->originalLogChannels = NULL;
    }
  }

  /**
   * The captured log records of a level whose message starts with a prefix.
   *
   * @return array[]
   */
  private function logged(string $level, string $messagePrefix): array {
    return array_values(array_filter($this->logRecords, function ($record) use ($level, $messagePrefix) {
      return $record['level'] === $level && strpos($record['message'], $messagePrefix) === 0;
    }));
  }

  /**
   * The captured log records at error level or worse.
   *
   * @return array[]
   */
  private function loggedErrors(): array {
    return array_values(array_filter($this->logRecords, function ($record) {
      return in_array($record['level'], ['emergency', 'alert', 'critical', 'error'], TRUE);
    }));
  }

}
