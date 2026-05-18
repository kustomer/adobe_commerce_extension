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
    $id = (int) $this->getRequest()->getParam('id');

    if ($id) {
      $row = $this->_webhookHelper->loadEventState($id);

      if (!$row) {
        $this->_messageManager->addErrorMessage(__('Event #%1 not found.', $id));
      } else {
        // isEventTerminal centralizes the terminal-failure definition shared
        // with requeueForRetry's SQL predicate, so changing what counts as
        // "terminal" only happens in one place.
        if ($this->_webhookHelper->isEventTerminal($row)) {
          // requeueForRetry returns false if a concurrent state change (cron
          // claim, double-clicked Retry button) means the row no longer matches
          // the terminal predicate. Surface that as an error so the admin gets
          // honest feedback instead of a false-positive "queued".
          $requeued = $this->_webhookHelper->requeueForRetry($id);
          if ($requeued) {
            $this->_messageManager->addSuccessMessage(__('Event #%1 queued for retry.', $id));
          } else {
            $this->_messageManager->addErrorMessage(__(
              'Event #%1 could not be queued for retry; its state changed concurrently. Refresh and try again.',
              $id
            ));
          }
        } else {
          $displayState = $row['state'] ?? 'unknown';
          $this->_messageManager->addErrorMessage(__(
            'Event #%1 cannot be retried in state "%2"; only terminally-failed events are eligible.',
            $id,
            $displayState
          ));
        }
      }
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
