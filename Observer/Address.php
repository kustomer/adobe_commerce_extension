<?php

namespace Kustomer\WebhookIntegration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Kustomer\WebhookIntegration\Helper\Data;

class Address implements ObserverInterface
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
    $address = $observer->getCustomerAddress();

    // Create the payload data
    $data = [
      'addresses' => [$this->helper->getAddress($address->getId())],
      'customers' => [$this->helper->getCustomer($address->getCustomerId())],
    ];

    // Create the payload event
    $event = [
      'name' => $observer->getEvent()->getName(),
      'type' => 'address',
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
