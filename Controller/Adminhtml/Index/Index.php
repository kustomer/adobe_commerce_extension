<?php

namespace Kustomer\WebhookIntegration\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
  /** @var PageFactory */
  private $pageFactory;

  public function __construct(Context $context, PageFactory $rawFactory)
  {
    $this->pageFactory = $rawFactory;

    parent::__construct($context);
  }

  public function execute()
  {
    $resultPage = $this->pageFactory->create();
    $resultPage->setActiveMenu('Kustomer_WebhookIntegration::kustomer');
    $resultPage
      ->getConfig()
      ->getTitle()
      ->prepend(__('Event Log'));

    return $resultPage;
  }
}
