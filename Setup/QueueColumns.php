<?php
namespace Kustomer\WebhookIntegration\Setup;

use Magento\Framework\DB\Ddl\Table;

/**
 * Single source of truth for the async queue columns added in setup_version 1.1.0.
 * Consumed by InstallSchema (fresh installs, newTable path) and UpgradeSchema
 * (existing installs, addColumn path) so the two paths cannot drift.
 */
class QueueColumns
{
  /**
   * Column name => Magento connection-style addColumn definition.
   *
   * @return array<string, array<string, mixed>>
   */
  public static function definitions(): array
  {
    return [
      'state' => [
        'type'     => Table::TYPE_TEXT,
        'length'   => 20,
        'nullable' => false,
        'default'  => 'pending',
        'comment'  => 'State',
      ],
      'retry_count' => [
        'type'     => Table::TYPE_INTEGER,
        'nullable' => false,
        'unsigned' => true,
        'default'  => 0,
        'comment'  => 'Retry Count',
      ],
      'next_attempt_at' => [
        'type'     => Table::TYPE_TIMESTAMP,
        'nullable' => true,
        'default'  => null,
        'comment'  => 'Next Attempt At',
      ],
      'locked_until' => [
        'type'     => Table::TYPE_TIMESTAMP,
        'nullable' => true,
        'default'  => null,
        'comment'  => 'Locked Until',
      ],
      'locked_by' => [
        'type'     => Table::TYPE_TEXT,
        'length'   => 64,
        'nullable' => true,
        'default'  => null,
        'comment'  => 'Locked By',
      ],
    ];
  }
}
