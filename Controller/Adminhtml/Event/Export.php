<?php

namespace Kustomer\WebhookIntegration\Controller\Adminhtml\Event;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Kustomer\WebhookIntegration\Helper\Data;

class Export extends Action
{
  /**
   * @var Data
   */
  protected $_webhookHelper;

  public function __construct(
    Context $context,
    ResultFactory $rawFactory,
    Data $helper,
    UrlInterface $urlBuilder
  ) {
    $this->_resultFactory = $rawFactory;
    $this->_webhookHelper = $helper;
    $this->_urlBuilder = $urlBuilder;

    parent::__construct($context);
  }

  public function execute()
  {
    // Export all events to JSON
    $this->_webhookHelper->export();

    // Redirect to the index page
    $resultRedirect = $this->_resultFactory->create(
      ResultFactory::TYPE_REDIRECT
    );
    $resultRedirect->setUrl(
      $this->_urlBuilder->getUrl('kustomer_webhookintegration/index/index')
    );

    return $resultRedirect;
  }
}
