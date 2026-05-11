<?php

namespace Kustomer\WebhookIntegration\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ObjectManager;
use Kustomer\WebhookIntegration\Model\EventFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Filesystem\Io\File;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;


class Data extends AbstractHelper
{
  /**
   * Maximum number of delivery attempts before a row transitions to terminal_failed.
   * Attempt 1 sets retry_count=1, ..., attempt MAX_RETRIES sets retry_count=MAX_RETRIES
   * which triggers terminal_failed on the next recordFailedAttempt call.
   */
  const MAX_RETRIES = 4;

  /**
   * Backoff ladder in seconds, indexed by new retry_count after increment.
   * [1m, 5m, 30m, 2h]. Out-of-range index clamps to last value.
   */
  private static $backoffLadder = [60, 300, 1800, 7200];

  /**
   * @var FileFactory
   */
  protected $_fileFactory;

  /**
   * @var DirectoryList
   */
  protected $_directoryList;

  /**
   * @var File
   */
  protected $_file;

  /**
   * @var AddressRepositoryInterface
   */
  protected $_addressRepository;

  /**
   * @var CustomerRepositoryInterface
   */
  protected $_customerRepository;

  /**
   * @var OrderRepositoryInterface
   */
  protected $_orderRepository;

  /**
   * @var \Magento\Framework\App\ResourceConnection
   */
  protected $_resourceConnection;

  public function __construct(
    Context $context,
    EventFactory $eventFactory,
    FileFactory $fileFactory,
    DirectoryList $directoryList,
    File $file,
    AddressRepositoryInterface $addressRepository,
    CustomerRepositoryInterface $customerRepository,
    OrderRepositoryInterface $orderRepository,
    \Magento\Framework\App\ResourceConnection $resourceConnection = null
  ) {
    parent::__construct($context);

    $this->logger = $context->getLogger();
    $this->eventFactory = $eventFactory;
    $this->_fileFactory = $fileFactory;
    $this->_directoryList = $directoryList;
    $this->_file = $file;
    $this->_addressRepository = $addressRepository;
    $this->_customerRepository = $customerRepository;
    $this->_orderRepository = $orderRepository;
    // ResourceConnection provides raw DB access needed for atomic queue operations.
    // Default argument allows the existing constructor signature to remain compatible.
    $this->_resourceConnection = $resourceConnection
      ?: ObjectManager::getInstance()->get(\Magento\Framework\App\ResourceConnection::class);
  }

  /**
   * @return string
   */
  private function findIntegration()
  {
    // Create an integration service interface
    $integrationService = ObjectManager::getInstance()
      ->get('\Magento\Integration\Api\IntegrationServiceInterface');

    // Get the integration by name
    $integration = $integrationService->findByName('kustomer');

    // Return the integration
    return $integration;
  }

  /**
   * @return string
   */
  private function getWebhookUrl()
  {
    // Get the integration
    $integration = $this->findIntegration();

    // If the integration is not found, return
    if (!$integration) {
      return;
    }

    // Get the identity_link_url from the integration via getData
    $webhookUrl = $integration->getData('identity_link_url');

    // Replace the word "oauth" with the word "orgs"
    $webhookUrl = str_replace('/oauth', '/orgs', $webhookUrl ?? '');

    // Replace the word "login" with "hooks/adobe-commerce"
    $webhookUrl = str_replace('/login', '/hooks/adobe-commerce', $webhookUrl ?? '');

    // Return the webhook URL
    return $webhookUrl;
  }

  /**
   * @return string
   */
  private function getSecurityToken()
  {
    // Get the integration
    $integration = $this->findIntegration();

    // If the integration is not found, return
    if (!$integration) {
      return;
    }

    // Create an oAuth service interface
    $oauthService = ObjectManager::getInstance()
      ->get('\Magento\Integration\Api\OauthServiceInterface');

    // Get the access token pair
    $accessTokenPair = $oauthService->getAccessToken($integration->getId());

    // If the access token pair is not found, return
    if (!$accessTokenPair) {
      return;
    }

    // Return the access token
    return $accessTokenPair->getToken();
  }

  /**
   * @return string
   */
  private function getMagentoVersion()
  {
    return ObjectManager::getInstance()
      ->get('Magento\Framework\App\ProductMetadataInterface')
      ->getVersion();
  }

  /**
   * @return string
   */
  private function getExtensionVersion()
  {
    $composerJson = file_get_contents(__DIR__ . '/../composer.json');
    $composerJson = json_decode($composerJson, true);

    return $composerJson['version'];
  }

  /**
   * @return array
   */
  private function getStoreData()
  {
    $store = ObjectManager::getInstance()
      ->get('\Magento\Store\Model\StoreManagerInterface')
      ->getStore();

    return [
      'id' => $store->getId(),
      'code' => $store->getCode(),
      'name' => $store->getName(),
      'website_id' => $store->getWebsiteId(),
      'group_id' => $store->getStoreGroupId(),
    ];
  }

  /**
   * @param array $payload
   * @return mixed[]
   * @throws \Exception
   */
  private function sendApiRequest(array $payload)
  {
    // Get the webhook URL and security token
    $url = $this->getWebhookUrl();
    $token = $this->getSecurityToken();

    // If either is not set, throw an exception and return
    if (!$url || !$token) {
      throw new \Exception('Kustomer webhook URL or security token is not set');
      return;
    }

    // Start cURL and encode the data
    $curl = curl_init();
    $encodedPayload = json_encode(
      $payload,
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    // Hash the token with the payload to generate an HMAC hex digest
    $hashedToken = base64_encode(
      hash_hmac('sha256', $encodedPayload, $token, true)
    );

    // Set cURL options
    curl_setopt_array($curl, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => $encodedPayload,
      CURLOPT_CONNECTTIMEOUT_MS => 3000,
      CURLOPT_TIMEOUT_MS => 10000,
      CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Token: ' . $hashedToken,
        'Content-Type: application/json',
        'Content-Length: ' . strlen($encodedPayload),
        'X-Version-Adobe-Commerce: ' . $this->getMagentoVersion(),
        'X-Version-Extension: ' . $this->getExtensionVersion(),
        'X-Version-PHP: ' . phpversion(),
      ],
    ]);

    try {
      // Submit the request
      $response = curl_exec($curl);

      // curl_exec returns false on failure (including timeouts)
      if ($response === false) {
        throw new \Exception(sprintf(
          'cURL error (%d): %s',
          curl_errno($curl),
          curl_error($curl)
        ));
      }

      $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

      // If there's an error, log and throw an exception
      if ($statusCode !== 200) {
        throw new \Exception(sprintf('HTTP %d: %s', $statusCode, $response));
      }
    } finally {
      // Always close the cURL session handle
      curl_close($curl);
    }

    // Return the response
    return json_decode($response, true);
  }

  // -------------------------------------------------------------------------
  // Queue helpers (added in PR 3 — used by cron consumer; not yet called by
  // send() or retry(), which remain synchronous until PR 4).
  // -------------------------------------------------------------------------

  /**
   * Map a state string to the legacy status integer mirror.
   *
   * @param string $state
   * @return int
   */
  public function stateToStatus(string $state): int
  {
    return $state === 'succeeded' ? 1 : 0;
  }

  /**
   * Enqueue a webhook payload as a new pending row.
   *
   * Fail-open: if the INSERT fails, a structured error is written to
   * var/log/kustomer_webhook_enqueue_failure.log and the exception is
   * swallowed so the merchant's commerce action is not blocked.
   *
   * @param array $payload
   * @return void
   */
  public function enqueue(array $payload): void
  {
    try {
      $connection = $this->_resourceConnection->getConnection();
      $tableName  = $this->_resourceConnection->getTableName('kustomer_webhook_integration_events');

      // Precondition: caller must populate $payload['event']['store'] via
      // getStoreData() before enqueue (mirrors today's send() behavior).
      // store_id is a NOT NULL FK to store.store_id; an unset/zero value
      // would surface as an FK violation and silently route to the
      // fail-open log. Fail loudly here so the bug is in the caller.
      $storeId = (int) ($payload['event']['store']['id'] ?? 0);
      if ($storeId <= 0) {
        throw new \InvalidArgumentException(
          'enqueue: payload missing event.store.id; caller must populate store data'
        );
      }

      $connection->insert($tableName, [
        'store_id'       => $storeId,
        'payload'        => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'status'         => 0,
        'uri'            => $this->getWebhookUrl(),
        'state'          => 'pending',
        'retry_count'    => 0,
        'next_attempt_at'=> new \Zend_Db_Expr('NOW()'),
        'locked_until'   => null,
        'locked_by'      => null,
      ]);
    } catch (\Exception $e) {
      // Fail open: do not rethrow. Write structured error to dedicated log file.
      $eventType  = $payload['event']['type']  ?? 'unknown';
      $eventName  = $payload['event']['name']  ?? 'unknown';
      $storeId    = $payload['event']['store']['id'] ?? null;
      $errorClass = get_class($e);
      // Truncate message to 200 chars to avoid leaking sensitive DB details.
      $errorMsg   = substr($e->getMessage(), 0, 200);

      $entry = json_encode([
        'timestamp'     => date('c'),
        'event_type'    => $eventType,
        'event_name'    => $eventName,
        'store_id'      => $storeId,
        'error_class'   => $errorClass,
        'error_message' => $errorMsg,
      ], JSON_UNESCAPED_SLASHES);

      $logPath = $this->_directoryList->getPath('var') . '/log/kustomer_webhook_enqueue_failure.log';
      error_log($entry . PHP_EOL, 3, $logPath);
    }
  }

  /**
   * Deliver a queued event over HTTP.
   *
   * Loads the stored payload and performs the HMAC + cURL exchange.
   * Does NOT touch any state column — that is the caller's responsibility.
   *
   * @param int    $eventId
   * @param string $workerId  (unused by HTTP layer, reserved for future tracing)
   * @return bool  true on HTTP 200, false on any error
   */
  public function deliverEvent(int $eventId, string $workerId): bool
  {
    $connection = $this->_resourceConnection->getConnection();
    $tableName  = $this->_resourceConnection->getTableName('kustomer_webhook_integration_events');

    $row = $connection->fetchRow(
      $connection->select()->from($tableName)->where('event_id = ?', $eventId)
    );

    if (!$row) {
      $this->logger->error('deliverEvent: event row not found', ['event_id' => $eventId]);
      return false;
    }

    $payload = json_decode($row['payload'], true);
    if ($payload === null) {
      $this->logger->error('deliverEvent: failed to decode payload', ['event_id' => $eventId]);
      return false;
    }

    try {
      $this->performHttpDelivery($payload);
      return true;
    } catch (\Exception $e) {
      $this->logger->error('deliverEvent: HTTP delivery failed', [
        'event_id'   => $eventId,
        'error_class' => get_class($e),
        'error'      => substr($e->getMessage(), 0, 200),
      ]);
      return false;
    }
  }

  /**
   * Transition a processing row back to 'failed'.
   *
   * Mirrors o-webhooks-worker convention: there is a single 'failed' state.
   * Terminal vs retryable is encoded by next_attempt_at:
   *   - retry_count >= MAX_RETRIES  → next_attempt_at = NULL (terminal)
   *   - else                        → next_attempt_at = NOW() + backoff (retryable)
   *
   * Uses a conditional UPDATE with locked_by = $expectedLockedBy so that
   * a stale worker cannot overwrite a row already reclaimed by another worker.
   *
   * @param int    $eventId
   * @param string $error             Short error description (no PII).
   * @param string $expectedLockedBy  Worker UUID that owns the lease.
   * @param bool   $requireExpiredLease  If true, also requires locked_until <= NOW().
   * @return bool  true if a row was updated, false if another worker already transitioned it.
   */
  public function recordFailedAttempt(
    int $eventId,
    string $error,
    string $expectedLockedBy,
    bool $requireExpiredLease = false
  ): bool {
    $connection = $this->_resourceConnection->getConnection();
    $tableName  = $this->_resourceConnection->getTableName('kustomer_webhook_integration_events');

    // Read current retry_count.
    $row = $connection->fetchRow(
      $connection->select()
        ->from($tableName, ['retry_count'])
        ->where('event_id = ?', $eventId)
        ->where('state = ?', 'processing')
        ->where('locked_by = ?', $expectedLockedBy)
    );

    if (!$row) {
      // Another worker already transitioned this row.
      return false;
    }

    $newRetryCount = (int)$row['retry_count'] + 1;

    // Build WHERE conditions as strings (bound separately) to avoid ambiguity
    // with Magento's update() array-where handling for non-placeholder entries.
    $whereConditions = [
      $connection->quoteInto('event_id = ?', $eventId),
      $connection->quoteInto('state = ?', 'processing'),
      $connection->quoteInto('locked_by = ?', $expectedLockedBy),
    ];
    if ($requireExpiredLease) {
      $whereConditions[] = 'locked_until <= NOW()';
    }
    $whereSql = implode(' AND ', $whereConditions);

    $data = [
      'state'       => 'failed',
      'status'      => $this->stateToStatus('failed'),
      'retry_count' => $newRetryCount,
      'error'       => substr($error, 0, 200),
      'locked_by'   => null,
      'locked_until'=> null,
    ];

    if ($newRetryCount >= self::MAX_RETRIES) {
      // Terminal: NULL next_attempt_at signals "won't retry."
      $data['next_attempt_at'] = null;
    } else {
      // Retryable: schedule next attempt per the backoff ladder.
      // newRetryCount is the post-increment count (1 after first failure),
      // so the ladder is indexed by (newRetryCount - 1) to use ladder[0]
      // for the wait between attempt 1 and attempt 2.
      $backoffSeconds = self::$backoffLadder[
        min($newRetryCount - 1, count(self::$backoffLadder) - 1)
      ];
      $data['next_attempt_at'] = new \Zend_Db_Expr(
        'DATE_ADD(NOW(), INTERVAL ' . (int)$backoffSeconds . ' SECOND)'
      );
    }

    $affected = $connection->update($tableName, $data, $whereSql);
    return $affected > 0;
  }

  /**
   * Reset a terminal row so the cron consumer will re-attempt delivery.
   * Called by the admin Retry action in PR 4. Not yet invoked in PR 3.
   *
   * Resets state to 'pending' with retry_count=0, mirroring a fresh enqueue.
   * The row is immediately eligible because next_attempt_at = NOW().
   *
   * Guarded by a conditional predicate so a double-clicked admin button
   * (or any caller that races with the cron consumer) cannot yank an
   * in-flight row out from under a worker. Only terminally-failed rows
   * are eligible, matching the gate in the admin Retry controller.
   *
   * @param int $eventId
   * @return bool true if a row was reset, false if it was not terminal-failed.
   */
  public function requeueForRetry(int $eventId): bool
  {
    $connection = $this->_resourceConnection->getConnection();
    $tableName  = $this->_resourceConnection->getTableName('kustomer_webhook_integration_events');

    $affected = $connection->update(
      $tableName,
      [
        'state'           => 'pending',
        'status'          => 0,
        'retry_count'     => 0,
        'next_attempt_at' => new \Zend_Db_Expr('NOW()'),
        'locked_by'       => null,
        'locked_until'    => null,
        'error'           => null,
      ],
      [
        'event_id = ?'      => $eventId,
        'state = ?'         => 'failed',
        'next_attempt_at IS NULL',
      ]
    );

    return $affected > 0;
  }

  // -------------------------------------------------------------------------
  // Internal HTTP delivery — shared by deliverEvent() and the legacy send()/retry()
  // -------------------------------------------------------------------------

  /**
   * Perform the HMAC-signed cURL POST to the Kustomer webhook endpoint.
   * Throws on non-200 or cURL error. Does not write any DB rows.
   *
   * @param array $payload
   * @return mixed  Decoded JSON response body.
   * @throws \Exception
   */
  private function performHttpDelivery(array $payload)
  {
    return $this->sendApiRequest($payload);
  }

  /**
   * @param array $payload
   * @param string|null $error
   * @return int
   */
  private function saveRequest(array $payload, $error)
  {
    // Create a new event model
    $event = $this->eventFactory->create();

    // Set the event data
    $event->setData([
      'payload' => json_encode($payload),
      'status' => $error !== null ? 0 : 1,
      'uri' => $this->getWebhookUrl(),
      'error' => $error,
    ]);

    // Save the event
    $event->save();

    // Return the event ID
    return $event->getId();
  }

  /**
   * @param array $payload
   * @param string|null $error
   * @param string $eventId
   * @return int
   */
  private function updateRequest(array $payload, $error, $eventId)
  {
    // Load the event
    $event = $this->eventFactory->create()->load($eventId);

    // Update the event data
    $event->addData([
      'payload' => json_encode($payload),
      'status' => $error !== null ? 0 : 1,
      'uri' => $this->getWebhookUrl(),
      'error' => $error,
      'last_sent_at' => date('Y-m-d H:i:s', time()),
    ]);

    // Update the event
    $event->save();

    // Return the event ID
    return $event->getId();
  }

  /**
   * @param string $id
   */
  public function getAddress($id)
  {
    // Get the address by ID
    $address = $this->_addressRepository->getById($id);

    // Return the address
    return [
      'id' => $id,
      'region_id' => $address->getRegionId(),
      'is_default_billing' => $address->isDefaultBilling(),
      'is_default_shipping' => $address->isDefaultShipping(),
    ];
  }

  /**
   * @param string $id
   */
  public function getCustomer($id)
  {
    // Get the customer by ID
    $customer = $this->_customerRepository->getById($id);

    // Return the customer
    return [
      'id' => $id,
      'email' => $customer->getEmail(),
      'created_at' => $customer->getCreatedAt(),
      'updated_at' => $customer->getUpdatedAt(),
    ];
  }

  /**
   * @param string $id
   */
  public function getOrder($id)
  {
    // Get the order by ID
    $order = $this->_orderRepository->get($id);

    // Return the order
    return [
      'id' => $id,
      'entity_id' => $order->getEntityId(),
      'increment_id' => $order->getIncrementId(),
      'quote_id' => $order->getQuoteId(),
      'customer_id' => $order->getCustomerId(),
      'created_at' => $order->getCreatedAt(),
      'updated_at' => $order->getUpdatedAt(),
    ];
  }

  /**
   * @param Order $order
   */
  public function getOrderAddresses($order)
  {
    // Get the customer ID
    $customerId = $order->getCustomerId();

    // Billing and shipping addresses
    $billingAddress = $order->getBillingAddress();
    $shippingAddress = $order->getShippingAddress();

    if($customerId) {
      return [
        $this->getAddress($billingAddress->getCustomerAddressId()),
        $this->getAddress($shippingAddress->getCustomerAddressId())
      ];
    } else {
      return [
        [
          'id' => $billingAddress->getId(),
          'region_id' => $billingAddress->getRegionId(),
          'is_default_billing' => false,
          'is_default_shipping' => false,
        ],
        [
          'id' => $shippingAddress->getId(),
          'region_id' => $shippingAddress->getRegionId(),
          'is_default_billing' => false,
          'is_default_shipping' => false,
        ]
      ];
    }
  }

  /**
   * @param Order $order
   */
  public function getOrderCustomers($order)
  {
    // Get the customer ID
    $customerId = $order->getCustomerId();

    if($customerId) {
      return [$this->getCustomer($customerId)];
    } else {
      return [
        [
          'id' => null,
          'email' => $order->getCustomerEmail(),
          'created_at' => null,
          'updated_at' => null,
        ]
      ];
    }
  }

  /**
   * @param array $payload
   */
  public function send($payload)
  {
    $token = $this->getSecurityToken();

    $this->logger->info('Sending data to Kustomer', [
      'event_type' => $payload['event']['type'] ?? null,
      'event_name' => $payload['event']['name'] ?? null,
    ]);

    // Set the event ID to null
    $eventId = null;

    // Add the store data to the payload event
    $payload['event']['store'] = $this->getStoreData();

    try {
      // Try to send the payload
      $response = $this->sendApiRequest($payload);

      // Log the success
      $this->logger->info('Data sent to Kustomer successfully');

      // And save the request
      $eventId = $this->saveRequest($payload, null);
    } catch (\Exception $e) {
      // Get the error message
      $message = $e->getMessage();

      // If there's an error, log it
      $this->logger->error('Error sending data to Kustomer', [
        'error' => $message,
      ]);

      // And save the error
      $eventId = $this->saveRequest($payload, $message);
    }

    // Log the event ID now that it was set
    $this->logger->info('Saved request to Magento DB', [
      'event_id' => $eventId,
    ]);
  }

  /**
   * @param string $eventId
   */
  public function retry($eventId)
  {
    // Get the payload
    $event = $this->eventFactory->create()->load($eventId);
    $payload = json_decode($event->getData('payload'), true);

    $this->logger->info('Retrying the sending of data to Kustomer', [
      'event_id'   => $eventId,
      'event_type' => $payload['event']['type'] ?? null,
      'event_name' => $payload['event']['name'] ?? null,
    ]);

    try {
      // Try to send the payload
      $response = $this->sendApiRequest($payload);

      // Log the success
      $this->logger->info('Data retry sent to Kustomer successfully', [
        'response' => $response,
      ]);

      // And update the request
      $this->updateRequest($payload, null, $eventId);
    } catch (\Exception $e) {
      // Get the error message
      $message = $e->getMessage();

      // If there's an error, log it
      $this->logger->error('Error retrying the sending of data to Kustomer', [
        'error' => $message,
      ]);

      // And update the error
      $this->updateRequest($payload, $message, $eventId);
    }

    // Log the event ID
    $this->logger->info('Saved request to Magento DB', [
      'event_id' => $eventId,
    ]);
  }

  public function export()
  {
    // Define the name of the export directory, its path, and the file name
    $dirName = 'export';
    $exportPath = $this->_directoryList->getPath('var') . '/' . $dirName;
    $downloadedFileName = 'kustomer-adobe-commerce-event-log-' . date('Ymd') . '.json';
    $filePath = $dirName . '/' . $downloadedFileName;

    // Get all events
    $events = $this->eventFactory
      ->create()
      ->getCollection()
      ->getData();

    // Store the data somewhere
    $data = [];

    // Loop through the events and append them to the data array.
    // Includes the async queue columns (state, retry_count, next_attempt_at,
    // locked_until, locked_by) added in setup_version 1.1.0 so support can
    // see queue state when triaging delivery issues.
    foreach ($events as $event) {
      $data[] = [
        'id'              => $event['event_id'],
        'store_id'        => $event['store_id'],
        'payload'         => json_decode($event['payload'], true),
        'status'          => $event['status'],
        'state'           => $event['state'] ?? null,
        'retry_count'     => $event['retry_count'] ?? null,
        'next_attempt_at' => $event['next_attempt_at'] ?? null,
        'locked_until'    => $event['locked_until'] ?? null,
        'locked_by'       => $event['locked_by'] ?? null,
        'uri'             => $event['uri'],
        'error'           => $event['error'],
        'created_at'      => $event['created_at'],
        'last_sent_at'    => $event['last_sent_at'],
      ];
    }

    // Check for existence of and, if necessary, and create export directory
    if (!is_dir($exportPath)) {
      $ioAdapter = $this->_file;
      $ioAdapter->mkdir($exportPath, 0775);
    }

    // Write the data to a file
    file_put_contents(
      $exportPath . '/' . $downloadedFileName,
      json_encode($data)
    );

    // Store some metadata on the file URL we'll generate
    $content['type'] = 'filename';
    $content['value'] = $filePath;
    $content['rm'] = 1;

    // Return the file for download
    return $this->_fileFactory->create(
      $downloadedFileName,
      $content,
      DirectoryList::VAR_DIR
    );
  }
}
