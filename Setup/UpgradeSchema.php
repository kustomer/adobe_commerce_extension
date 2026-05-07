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
      // status=1 => success, status=0 => terminal_failed, NULL => pending.
      $backfillDefaults = [
        'retry_count'     => 0,
        'next_attempt_at' => null,
        'locked_until'    => null,
        'locked_by'       => null,
      ];

      $backfills = [
        ['state' => 'success',         'where' => ['status = ?' => 1]],
        ['state' => 'terminal_failed', 'where' => ['status = ?' => 0]],
        ['state' => 'pending',         'where' => ['status IS NULL']],
      ];

      foreach ($backfills as $backfill) {
        $connection->update(
          $tableName,
          array_merge(['state' => $backfill['state']], $backfillDefaults),
          $backfill['where']
        );
      }
    }

    $setup->endSetup();
  }
}
