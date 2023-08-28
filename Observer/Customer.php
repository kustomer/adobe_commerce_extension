<?php

namespace Kustomer\WebhookIntegration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Kustomer\WebhookIntegration\Helper\Data;

class Customer implements ObserverInterface
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
    $customer = $observer->getCustomer();

    // Create the payload data
    $data = [
      'addresses' => array_map(function ($address) {
        return $this->helper->getAddress($address->getId());
      }, $customer->getAddresses()),
      'customers' => [$this->helper->getCustomer($customer->getId())]
    ];

    // Create the payload event
    $event = [
      'name' => $observer->getEvent()->getName(),
      'type' => 'customer',
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
