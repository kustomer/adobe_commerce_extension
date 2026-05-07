<?php

namespace Kustomer\WebhookIntegration\Cron;

use Kustomer\WebhookIntegration\Helper\Data as WebhookHelper;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Cron consumer that processes the async webhook delivery queue.
 *
 * Runs every minute (see etc/crontab.xml). On a queue with zero pending rows
 * this class exits immediately without side effects, so it is safe to deploy
 * before send() is flipped to enqueue mode (PR 4).
 *
 * Concurrency model
 * -----------------
 * Multiple cron workers (multi-node or overlapping runs) are safe because
 * the claim step is a single atomic UPDATE filtered by state and next_attempt_at.
 * Each worker generates a unique $workerId per execution; subsequent UPDATEs
 * require locked_by = $workerId so a stale worker can never overwrite a row
 * that another worker has already reclaimed.
 */
class ProcessQueue
{
  /**
   * Number of rows each cron run claims and processes in one batch.
   */
  const BATCH_SIZE = 10;

  /**
   * @var WebhookHelper
   */
  private $helper;

  /**
   * @var ResourceConnection
   */
  private $resourceConnection;

  /**
   * @var LoggerInterface
   */
  private $logger;

  public function __construct(
    WebhookHelper $helper,
    ResourceConnection $resourceConnection,
    LoggerInterface $logger
  ) {
    $this->helper             = $helper;
    $this->resourceConnection = $resourceConnection;
    $this->logger             = $logger;
  }

  /**
   * Main entry point, called by the Magento cron scheduler every minute.
   *
   * @return void
   */
  public function execute(): void
  {
    $workerId  = bin2hex(random_bytes(16));
    $connection = $this->resourceConnection->getConnection();
    $tableName  = $this->resourceConnection->getTableName('kustomer_webhook_integration_events');

    // ------------------------------------------------------------------
    // Step 1: Recover rows whose lease expired (crashed worker recovery).
    // ------------------------------------------------------------------
    $this->recoverExpiredLeases($connection, $tableName);

    // ------------------------------------------------------------------
    // Step 2: Atomically claim a batch of pending / retryable rows.
    // ------------------------------------------------------------------
    $claimedIds = $this->claimBatch($connection, $tableName, $workerId);

    if (empty($claimedIds)) {
      return;
    }

    $this->logger->info('Kustomer cron: processing batch', [
      'worker_id' => $workerId,
      'count'     => count($claimedIds),
    ]);

    // ------------------------------------------------------------------
    // Step 3: Deliver each claimed row and record the outcome.
    // ------------------------------------------------------------------
    foreach ($claimedIds as $eventId) {
      $this->processRow($connection, $tableName, (int)$eventId, $workerId);
    }
  }

  // -----------------------------------------------------------------------
  // Private helpers
  // -----------------------------------------------------------------------

  /**
   * Find all rows that are still in state='processing' but whose lease has
   * expired (crashed-worker recovery).  For each, call recordFailedAttempt
   * with requireExpiredLease=true so a concurrent worker cannot steal
   * a row whose lease is still valid.
   */
  private function recoverExpiredLeases($connection, string $tableName): void
  {
    $expiredRows = $connection->fetchAll(
      $connection->select()
        ->from($tableName, ['event_id', 'locked_by'])
        ->where('state = ?', 'processing')
        ->where('locked_until <= NOW()')
    );

    foreach ($expiredRows as $row) {
      $updated = $this->helper->recordFailedAttempt(
        (int)$row['event_id'],
        'lease expired',
        (string)$row['locked_by'],
        true  // requireExpiredLease
      );

      if (!$updated) {
        // Another concurrent worker already handled this row — that is fine.
        $this->logger->info('Kustomer cron: expired-lease row already recovered by another worker', [
          'event_id' => $row['event_id'],
        ]);
      }
    }
  }

  /**
   * Claim up to BATCH_SIZE rows with a single atomic UPDATE, then SELECT
   * back the claimed event IDs.
   *
   * MySQL note: INTERVAL N MINUTE without quotes — Postgres-style
   * INTERVAL '5 MINUTE' is intentionally avoided.
   *
   * @return int[]
   */
  private function claimBatch($connection, string $tableName, string $workerId): array
  {
    // Atomic claim: transition pending/retryable rows to processing.
    // Mirrors o-webhooks-worker convention: retryable failures share the
    // 'failed' state with terminal failures, distinguished by next_attempt_at.
    // The IS NOT NULL + <= NOW() predicate excludes terminal failures
    // (next_attempt_at IS NULL) naturally.
    $claimSql = "UPDATE `{$tableName}`
SET
    `state`        = 'processing',
    `status`       = 0,
    `locked_until` = DATE_ADD(NOW(), INTERVAL 5 MINUTE),
    `locked_by`    = :worker_id
WHERE `state` IN ('pending', 'failed')
  AND `next_attempt_at` IS NOT NULL
  AND `next_attempt_at` <= NOW()
ORDER BY `created_at` ASC
LIMIT :batch_size";

    $connection->query($claimSql, [
      'worker_id'  => $workerId,
      'batch_size' => self::BATCH_SIZE,
    ]);

    // Read back the rows we just claimed.
    $rows = $connection->fetchAll(
      $connection->select()
        ->from($tableName, ['event_id'])
        ->where('state = ?', 'processing')
        ->where('locked_by = ?', $workerId)
    );

    return array_column($rows, 'event_id');
  }

  /**
   * Attempt delivery for a single claimed row and write the outcome.
   */
  private function processRow($connection, string $tableName, int $eventId, string $workerId): void
  {
    try {
      $success = $this->helper->deliverEvent($eventId, $workerId);
    } catch (\Throwable $t) {
      $success = false;
      $errorMsg = substr($t->getMessage(), 0, 200);
      $this->logger->error('Kustomer cron: unexpected exception in deliverEvent', [
        'event_id'    => $eventId,
        'error_class' => get_class($t),
        'error'       => $errorMsg,
      ]);
    }

    if ($success) {
      // Conditional UPDATE: only write if we still own the lock.
      $successSql = "UPDATE `{$tableName}`
SET
    `state`           = 'succeeded',
    `status`          = 1,
    `locked_by`       = NULL,
    `locked_until`    = NULL,
    `next_attempt_at` = NULL,
    `last_sent_at`    = NOW(),
    `error`           = NULL
WHERE `event_id` = :event_id
  AND `state`    = 'processing'
  AND `locked_by`= :worker_id";

      $stmt     = $connection->query($successSql, [
        'event_id'  => $eventId,
        'worker_id' => $workerId,
      ]);
      $affected = $stmt->rowCount();

      if ($affected === 0) {
        $this->logger->warning('Kustomer cron: success UPDATE matched 0 rows (stale worker)', [
          'event_id'  => $eventId,
          'worker_id' => $workerId,
        ]);
      }
    } else {
      // Resolve the error string from deliverEvent's structured log or use a placeholder.
      $errorMsg = 'delivery failed';

      $updated = $this->helper->recordFailedAttempt(
        $eventId,
        $errorMsg,
        $workerId,
        false  // requireExpiredLease
      );

      if (!$updated) {
        $this->logger->warning('Kustomer cron: failure transition matched 0 rows (stale worker)', [
          'event_id'  => $eventId,
          'worker_id' => $workerId,
        ]);
      }
    }
  }
}
