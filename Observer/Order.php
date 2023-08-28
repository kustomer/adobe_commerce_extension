<?php

namespace Kustomer\WebhookIntegration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Kustomer\WebhookIntegration\Helper\Data;

class Order implements ObserverInterface
{
  /**
   * Constructor
   * @param Data $helper
   */
  public function __construct(Data $helper)
  {
    $this->helper = $helper;
  }

  public function execute(Observer $observer)
  {
    // Get the data
    $order = $observer->getOrder();

    // Create the payload data
    $data = [
      'addresses' => $this->helper->getOrderAddresses($order),
      'customers' => $this->helper->getOrderCustomers($order),
      'orders' => [$this->helper->getOrder($order->getId())],
    ];

    // Create the payload event
    $event = [
      'name' => $observer->getEvent()->getName(),
      'type' => 'order',
    ];

    // Create the payload
    $payload = [
      'data' => $data,
      'event' => $event,
    ];

    // Send the data to Kustomer
    $this->helper->send($payload);
  }
}
