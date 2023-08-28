<?php

namespace Kustomer\WebhookIntegration\Controller\Adminhtml\Event;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Kustomer\WebhookIntegration\Helper\Data;

class Retry extends Action
{
  /**
   * @var ResultFactory
   */
  protected $_resultFactory;

  /**
   * @var Data
   */
  protected $_webhookHelper;

  /**
   * @var ManagerInterface
   */
  protected $_messageManager;

  /**
   * @var UrlInterface
   */
  protected $_urlBuilder;

  public function __construct(
    Context $context,
    ResultFactory $rawFactory,
    ManagerInterface $messageManager,
    Data $helper,
    UrlInterface $urlBuilder
  ) {
    $this->_resultFactory = $rawFactory;
    $this->_messageManager = $messageManager;
    $this->_webhookHelper = $helper;
    $this->_urlBuilder = $urlBuilder;

    parent::__construct($context);
  }

  public function execute()
  {
    // Get the ID of the event to retry
    $id = $this->getRequest()->getParam('id');

    // If the ID is set, retry the event and show a success message
    if ($id) {
      $this->_webhookHelper->retry($id);

      $this->_messageManager->addSuccessMessage(__('Retried event #' . $id));
    }

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
