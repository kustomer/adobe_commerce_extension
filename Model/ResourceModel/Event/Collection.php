<?php
namespace Kustomer\WebhookIntegration\Model\ResourceModel\Event;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
  protected $_idFieldName = 'event_id';
  protected $_eventPrefix = 'kustomer_webhook_integration_events_collection';
  protected $_eventObject = 'event_collection';

  /**
   * Define resource model
   *
   * @return void
   */
  protected function _construct()
  {
    $this->_init(
      'Kustomer\WebhookIntegration\Model\Event',
      'Kustomer\WebhookIntegration\Model\ResourceModel\Event'
    );
  }
}
