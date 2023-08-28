<?php
namespace Kustomer\WebhookIntegration\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;

class InstallSchema implements InstallSchemaInterface
{
  public function install(
    SchemaSetupInterface $setup,
    ModuleContextInterface $context
  ) {
    $tableName = 'kustomer_webhook_integration_events';

    $setup->startSetup();

    if (!$setup->tableExists($tableName)) {
      $table = $setup
        ->getConnection()
        ->newTable($setup->getTable($tableName))
        ->addColumn(
          'event_id',
          Table::TYPE_INTEGER,
          null,
          [
            'identity' => true,
            'nullable' => false,
            'primary' => true,
            'unsigned' => true,
          ],
          'Event ID'
        )
        ->addColumn(
          'store_id',
          Table::TYPE_SMALLINT,
          null,
          [
            'nullable' => false,
            'unsigned' => true,
          ],
          'Store ID'
        )
        ->addColumn('payload', Table::TYPE_TEXT, 409600, [], 'Payload')
        ->addColumn('status', Table::TYPE_INTEGER, 1, [], 'Status')
        ->addColumn('uri', Table::TYPE_TEXT, 255, [], 'URI')
        ->addColumn(
          'error',
          Table::TYPE_TEXT,
          409600,
          ['nullable' => true, 'default' => ''],
          'Error Message'
        )
        ->addColumn(
          'created_at',
          Table::TYPE_TIMESTAMP,
          null,
          ['nullable' => false, 'default' => Table::TIMESTAMP_INIT],
          'Created At'
        )
        ->addColumn(
          'last_sent_at',
          Table::TYPE_TIMESTAMP,
          null,
          ['nullable' => false, 'default' => Table::TIMESTAMP_INIT_UPDATE],
          'Last Sent At'
        )
        ->setComment('Kustomer Webhook Event Table');

      $setup->getConnection()->createTable($table);

      $setup
        ->getConnection()
        ->addForeignKey(
          $setup->getFkName($tableName, 'store_id', 'store', 'store_id'),
          $setup->getTable($tableName),
          'store_id',
          $setup->getTable('store'),
          'store_id',
          Table::ACTION_CASCADE
        );
    }

    $setup->endSetup();
  }
}
