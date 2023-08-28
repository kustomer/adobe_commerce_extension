<?php
namespace Kustomer\WebhookIntegration\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

class Event extends AbstractDb
{
  public function __construct(Context $context)
  {
    parent::__construct($context);
  }

  protected function _construct()
  {
    $this->_init('kustomer_webhook_integration_events', 'event_id');
  }
}
