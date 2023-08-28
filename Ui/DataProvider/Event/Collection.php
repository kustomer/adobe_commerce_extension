<?php
namespace Kustomer\WebhookIntegration\Ui\DataProvider\Event;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
  protected function _initSelect()
  {
    $this->addFilterToMap('event_id', 'main_table.event_id');

    parent::_initSelect();
  }
}
