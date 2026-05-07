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
        $state = $row['state'] ?? null;

        // Allow retry only for terminal failures. In the o-webhooks-worker-aligned
        // state model, "terminal" is encoded as state='failed' AND next_attempt_at IS NULL
        // (matches the canonical "failed transaction with no nextRetry" pattern).
        // Also allow for unmigrated edge-case rows where state is NULL and status = 0.
        $isTerminal = (
          $state === 'failed' && $row['next_attempt_at'] === null
        ) || (
          $state === null && (int)$row['status'] === 0
        );

        if ($isTerminal) {
          $this->_webhookHelper->requeueForRetry($id);
          $this->_messageManager->addSuccessMessage(__('Event #%1 queued for retry.', $id));
        } else {
          $displayState = $state ?? 'unknown';
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
