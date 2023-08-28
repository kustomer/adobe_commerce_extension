<?php

namespace Kustomer\WebhookIntegration\Ui\Component\Event\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\UrlInterface;

class Actions extends Column
{
  /** @var UrlInterface */
  protected $_urlBuilder;

  /**
   * @var string
   */
  protected $_viewUrl;

  /**
   * @var ContextInterface
   */
  protected $_context;

  public function __construct(
    ContextInterface $context,
    UiComponentFactory $uiComponentFactory,
    UrlInterface $urlBuilder,
    $viewUrl = '',
    array $components = [],
    array $data = []
  ) {
    $this->_urlBuilder = $urlBuilder;
    $this->_viewUrl = $viewUrl;
    $this->_context = $context;
    parent::__construct($context, $uiComponentFactory, $components, $data);
  }

  /**
   * Prepare Data Source
   *
   * @param array $dataSource
   * @return array
   */
  public function prepareDataSource(array $dataSource)
  {
    if (isset($dataSource['data']['items'])) {
      $storeId = $this->_context->getFilterParam('store_id');
      foreach ($dataSource['data']['items'] as &$item) {
        $name = $this->getData('name');
        if (isset($item['event_id'])) {
          $item[$name]['view'] = [
            'href' => $this->_urlBuilder->getUrl($this->_viewUrl, [
              'id' => $item['event_id'],
              'store' => $storeId,
            ]),
            'label' => __('Retry'),
          ];
        }
      }
    }

    return $dataSource;
  }
}
