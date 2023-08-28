<?php
namespace Kustomer\WebhookIntegration\Model;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\DataObject\IdentityInterface;

class Event extends AbstractModel implements IdentityInterface
{
  const CACHE_TAG = 'kustomer_webhook_integration_events';

  protected $_cacheTag = 'kustomer_webhook_integration_events';
  protected $_eventPrefix = 'kustomer_webhook_integration_events';

  protected function _construct()
  {
    $this->_init('Kustomer\WebhookIntegration\Model\ResourceModel\Event');
  }

  public function getIdentities()
  {
    return [self::CACHE_TAG . '_' . $this->getId()];
  }

  public function getDefaultValues()
  {
    $values = [];

    return $values;
  }
}
