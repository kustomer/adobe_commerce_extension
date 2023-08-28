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

  public function __construct(
    Context $context,
    EventFactory $eventFactory,
    FileFactory $fileFactory,
    DirectoryList $directoryList,
    File $file,
    AddressRepositoryInterface $addressRepository,
    CustomerRepositoryInterface $customerRepository,
    OrderRepositoryInterface $orderRepository
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
    $composerJson = file_get_contents(
      $this->_directoryList->getPath('app') .
        '/code/Kustomer/WebhookIntegration/composer.json'
    );
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

    // Submit the request
    $response = curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    // If there's an error, log and throw an exception
    if ($statusCode !== 200) {
      throw new \Exception($response);
    }

    // Close cURL session handle
    curl_close($curl);

    // Return the response
    return json_decode($response, true);
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
    // Log the payload
    $this->logger->info('Sending data to Kustomer', $payload);

    $token = $this->getSecurityToken();
    $this->logger->info('Security token', [ 'token' => $token ]);

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

    // Log the payload
    $this->logger->info('Retrying the sending of data to Kustomer', $payload);

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

    // Loop through the events and append them to the data array
    foreach ($events as $event) {
      $data[] = [
        'id' => $event['event_id'],
        'store_id' => $event['store_id'],
        'payload' => json_decode($event['payload'], true),
        'status' => $event['status'],
        'uri' => $event['uri'],
        'error' => $event['error'],
        'created_at' => $event['created_at'],
        'last_sent_at' => $event['last_sent_at'],
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
