<?php
namespace Kustomer\WebhookIntegration\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
  public function upgrade(
    SchemaSetupInterface $setup,
    ModuleContextInterface $context
  ) {
    $setup->startSetup();

    if (version_compare($context->getVersion(), '1.1.0', '<')) {
      $tableName = $setup->getTable('kustomer_webhook_integration_events');
      $connection = $setup->getConnection();

      // Idempotent column adds — guard each so a partial-upgrade rerun
      // doesn't fail with ER_DUP_FIELDNAME. Definitions are shared with
      // InstallSchema via QueueColumns::definitions() to prevent drift.
      foreach (QueueColumns::definitions() as $name => $def) {
        if (!$connection->tableColumnExists($tableName, $name)) {
          $connection->addColumn($tableName, $name, $def);
        }
      }

      // Backfill state from legacy status column for existing rows.
      // Mirrors o-webhooks-worker convention (see dev-monorepo/js/o-webhooks-worker
      // service/transaction_service.js): succeeded vs failed are the only
      // terminal state values; retryable-vs-terminal is encoded by
      // next_attempt_at — a non-NULL future timestamp means "will retry,"
      // NULL means "won't retry."
      //   status=1 (legacy success)  => succeeded, next_attempt_at NULL
      //   status=0 (legacy failure)  => failed,    next_attempt_at NULL (terminal)
      //   status IS NULL             => pending,   next_attempt_at NOW()
      // Guarded against partially-altered schemas: if the table or the legacy
      // status column is missing, skip the backfill rather than aborting setup.
      if (
        $setup->tableExists($tableName)
        && $connection->tableColumnExists($tableName, 'status')
        && $connection->tableColumnExists($tableName, 'state')
      ) {
        $lockReset = [
          'retry_count'  => 0,
          'locked_until' => null,
          'locked_by'    => null,
        ];

        $backfills = [
          [
            'where'  => ['status = ?' => 1],
            'values' => array_merge(['state' => 'succeeded', 'next_attempt_at' => null], $lockReset),
          ],
          [
            'where'  => ['status = ?' => 0],
            'values' => array_merge(['state' => 'failed', 'next_attempt_at' => null], $lockReset),
          ],
          [
            'where'  => ['status IS NULL'],
            'values' => array_merge(
              ['state' => 'pending', 'next_attempt_at' => new \Zend_Db_Expr('NOW()')],
              $lockReset
            ),
          ],
        ];

        foreach ($backfills as $backfill) {
          $connection->update($tableName, $backfill['values'], $backfill['where']);
        }
      }
    }

    $setup->endSetup();
  }
}
