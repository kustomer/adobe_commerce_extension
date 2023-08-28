<?php
namespace Kustomer\WebhookIntegration\Ui\Component\Event\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class Status extends Column
{
  /**
   * Column name
   */
  const STATUS = 'column.status';

  /**
   * Prepare Data Source
   *
   * @param array $dataSource
   * @return array
   */
  public function prepareDataSource(array $dataSource)
  {
    if (isset($dataSource['data']['items'])) {
      $fieldName = $this->getData('status');
      foreach ($dataSource['data']['items'] as &$item) {
        if (isset($item[$fieldName])) {
          $item[$fieldName] = $this->getStatus($item[$fieldName]);
        }
      }
    }

    return $dataSource;
  }

  /**
   * @param $status
   * @return \Magento\Framework\Phrase
   */
  private function getStatus($status)
  {
    if ($status == 1) {
      return __('Success');
    } else {
      return __('Failed');
    }
  }
}
