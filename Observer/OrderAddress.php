<?php

namespace Kustomer\WebhookIntegration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Kustomer\WebhookIntegration\Helper\Data;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrderAddress implements ObserverInterface
{
  /**
   * Constructor
   * @param Data $helper
   * @param OrderRepositoryInterface $orderRepository
   */
  public function __construct(Data $helper, OrderRepositoryInterface $orderRepository)
  {
    $this->helper = $helper;
    $this->_orderRepository = $orderRepository;
  }

  public function execute(Observer $observer)
  {
    // Get the order data from the order_id
    $orderId = $observer->getData('order_id');
    $order = $this->_orderRepository->get($orderId);

    // Create the payload data
    $data = [
      'addresses' => $this->helper->getOrderAddresses($order),
      'customers' => $this->helper->getOrderCustomers($order),
      'orders' => [$this->helper->getOrder($orderId)]
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
