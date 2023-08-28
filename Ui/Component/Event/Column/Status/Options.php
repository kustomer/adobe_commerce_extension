<?php
namespace Kustomer\WebhookIntegration\Ui\Component\Event\Column\Status;

use Magento\Framework\Data\OptionSourceInterface;

class Options implements OptionSourceInterface
{
  /**
   * @var array
   */
  protected $options;

  /**
   * Get options
   *
   * @return array
   */
  public function toOptionArray()
  {
    if ($this->options !== null) {
      return $this->options;
    }

    $this->options = [
      [
        'label' => __('Success'),
        'value' => 1,
      ],
      [
        'label' => __('Failed'),
        'value' => 0,
      ],
    ];

    return $this->options;
  }
}
