<?php

namespace Drupal\leadtrekker\Plugin\Block;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;
use Drupal\leadtrekker\LeadtrekkerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'leadtrekker' block.
 *
 * @Block(
 *   id = "leadtrekker_block",
 *   admin_label = @Translation("Leadtrekker Recent Leads"),
 * )
 */
class LeadtrekkerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Json Service.
   *
   * @var \Drupal\Component\Serialization\Json
   */
  protected $json;

  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * LeadtrekkerBlock constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client used to fetch remote definitions.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger
   *   Defines a factory for logging channels.
   * @param \Drupal\Core\Datetime\DateFormatter $dateFormatter
   *   The date formatter service.
   * @param \Drupal\Component\Serialization\Json $json
   *   Default serialization for JSON.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $httpClient, LoggerChannelFactory $logger, DateFormatter $dateFormatter, Json $json, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $httpClient;
    $this->loggerFactory = $logger;
    $this->dateFormatter = $dateFormatter;
    $this->json = $json;
    $this->configFactory = $config_factory->getEditable('leadtrekker.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('logger.factory'),
      $container->get('date.formatter'),
      $container->get('serialization.json'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    if ($account->hasPermission('view recent leadtrekker leads')) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $leads = $this->leadtrekkerGetRecent();

    // This part of the Leadtrekker API returns HTTP error codes on failure, with
    // no message.
    if (!empty($leads['Error']) || $leads['HTTPCode'] != 200) {
      $output = $this->t('An error occurred when fetching the Leadtrekker leads data: @error', array(
        '@error' => !empty($leads['Error']) ? $leads['Error'] : $leads['HTTPCode'],
      ));

      return [
        '#type' => 'markup',
        '#markup' => $output,
      ];

    }
    elseif (empty($leads['Data'])) {
      $output = $this->t('No leads to show.');
      return [
        '#type' => 'markup',
        '#markup' => $output,
      ];
    }

    $items = array();

    foreach ($leads['Data']['contacts'] as $lead) {
      $first_name = isset($lead['properties']['firstname']['value']) ? $lead['properties']['firstname']['value'] : NULL;
      $last_name = isset($lead['properties']['lastname']['value']) ? $lead['properties']['lastname']['value'] : NULL;
      $url = Url::fromUri($lead['profile-url']);
      $items[] = [
        '#markup' => Link::fromTextAndUrl($first_name . ' ' .
          $last_name, $url)->toString() . ' ' . $this->t('(@time ago)',
          [
            '@time' => $this->dateFormatter->formatInterval(REQUEST_TIME - floor($lead['addedAt'] / 1000)),
          ]
        ),
      ];
    }

    $build = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];

    return $build;

  }

  /**
   * Gets the most recent Leadtrekker leads.
   *
   * @param int $n
   *   The number of leads to fetch.
   *
   * @see http://docs.hubapi.com/wiki/Searching_Leads
   *
   * @return array
   *   Returns array of recent leadtrekker leads activity.
   */
  public function leadtrekkerGetRecent($n = 5) {
    $access_token = $this->configFactory->get('leadtrekker_access_token');
    $n = intval($n);

    if (empty($access_token)) {
      return ['Error' => $this->t('This site is not connected to a Leadtrekker Account.')];
    }

    $api = 'https://api.hubapi.com/contacts/v1/lists/recently_updated/contacts/recent';

    $options = [
      'query' => [
        'access_token' => $access_token,
        'count' => $n,
      ],
    ];
    $url = Url::fromUri($api, $options)->toString();

    if ($this->configFactory->get('leadtrekker_expires_in') > REQUEST_TIME) {
      $result = $this->httpClient->get($url);
    }
    else {
      $refresh = $this->leadtrekkerOauthRefresh();
      if ($refresh) {
        $access_token = $this->configFactory->get('leadtrekker_access_token');
        $options = [
          'query' => [
            'access_token' => $access_token,
            'count' => $n,
          ],
        ];
        $url = Url::fromUri($api, $options)->toString();
        $result = $this->httpClient->get($url);

      }
    }
    return [
      'Data' => json_decode($result->getBody(), TRUE),
      'Error' => isset($result->error) ? $result->error : '',
      'HTTPCode' => $result->getStatusCode(),
    ];
  }

  /**
   * Refreshes Leadtrekker OAuth Access Token when expired.
   */
  public function leadtrekkerOauthRefresh() {

    $refresh_token = $this->configFactory->get('leadtrekker_refresh_token');
    $api = 'https://api.hubapi.com/auth/v1/refresh';
    $string = 'refresh_token=' . $refresh_token . '&client_id=' . LeadtrekkerInterface::HUBSPOT_CLIENT_ID . '&grant_type=refresh_token';
    $request_options = [
      RequestOptions::HEADERS => ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
      // @TODO encode string paramters using json::encode.
      RequestOptions::BODY => $string,
    ];
    try {
      $response = $this->httpClient->request('POST', $api, $request_options);

      if ($response->getStatusCode() == '200') {

        $data = $this->json->decode($response->getBody());
        $leadtrekker_access_token = $data['access_token'];
        $leadtrekker_refresh_token = $data['refresh_token'];

        $leadtrekker_expires_in = $data['expires_in'];

        $this->configFactory->set('leadtrekker_access_token', $leadtrekker_access_token)->save();
        $this->configFactory->set('leadtrekker_refresh_token', $leadtrekker_refresh_token)->save();
        $this->configFactory->set('leadtrekker_expires_in', ($leadtrekker_expires_in + REQUEST_TIME))->save();

        return ['value' => $data];

      }
    }
    catch (RequestException $e) {
      watchdog_exception('Leadtrekker', $e);
    }

    drupal_set_message($this->t('Refresh token failed with Error Code "%code: %status_message". Reconnect to your Leadtrekker
      account.'), 'error', FALSE);
    $this->loggerFactory->get('leadtrekker')->notice('Refresh token failed with Error Code "%code: %status_message". Visit the Leadtrekker module
      settings page and reconnect to your Leadtrekker account.', [
        '%code' => $response->getStatusCode(),
        '%status_message' => $response['status_message'],
      ]
    );

    return FALSE;

  }

}
