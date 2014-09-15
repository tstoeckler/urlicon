<?php

/**
 * @file
 * Contains \Drupal\urlicon\Plugin\Filter\UrlIcon.
 */

namespace Drupal\urlicon\Plugin\Filter;

use Drupal\Component\Utility\String;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a filter to add icons to urls.
 *
 * @Filter(
 *   id = "urlicon",
 *   title = @Translation("Url icon filter"),
 *   description = @Translation("Adds favicons to URLs."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
 *   settings = {
 *     "urlicon" = UI_FORMAT_FAVICON,
 *   },
 * )
 */
class UrlIcon extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The http client.
   *
   * @var \Guzzle\Http\Client
   */
  protected $client;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Client $client, LoggerChannelInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->client = $client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('http_client'), $container->get('logger.channel.urlicon'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();

    $config['urlicon'] = UI_FORMAT_FAVICON;

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['urlicon'] = [
      '#type' => 'radios',
      '#title' => $this->t('Filter URLs'),
      '#default_value' => $this->settings['urlicon'],
      '#options' => [
        UI_FORMAT_FAVICON => $this->t('Find all external URLs and append the according favicon (if available)'),
        UI_FORMAT_ICON    => $this->t('Find all external URLs and append an <em>external link icon</em>'),
        UI_FORMAT_CLASS   => $this->t('Find all external URLs and add a CSS class only (theme it as you like)'),
      ],
      '#description' => $this->t('Choose what to add to a link in the markup.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    // check for directory
    $dir = UI_FILE_PATH;
    file_prepare_directory($dir, FILE_CREATE_DIRECTORY);

    $reg_exp = '/<a.+?href=\"((http|https|ftp|telnet|news|mms):\/\/.+?)\"[^>]*>(.+?)<\/a>/i';

    $ui_format = $this->settings['urlicon'];
    switch ($ui_format) {
      case UI_FORMAT_FAVICON:
        $text = preg_replace_callback($reg_exp, [$this, 'formatFavicon'], $text);
        break;
      case UI_FORMAT_ICON:
        $text = preg_replace_callback($reg_exp, [$this, 'formatIcon'], $text);
        break;
      case UI_FORMAT_CLASS:
        $text = preg_replace_callback($reg_exp, [$this, 'formatClass'], $text);
        break;
    }

    return new FilterProcessResult($text);
  }

  protected function checkUrl($url) {
    return String::checkPlain(UrlHelper::stripDangerousProtocols($url));
  }

  public function formatFavicon($match) {
    // Define acceptable Content-Types
    // see http://www.iana.org/assignments/media-types/image/vnd.microsoft.icon
    // Additional Content-Types suggested by W3C
    // see http://www.w3.org/2005/10/howto-favicon
    $ui_ctype = [
      // Suggested by IANA
      'application/ico',
      'application/octet-stream',
      'image/vnd.microsoft.icon',
      'image/ico',
      'image/icon',
      'image/x-icon',
      'text/ico',
      'text/plain',
      // Suggested by W3C
      'image/gif',
      'image/png',
    ];
    $dir = UI_FILE_PATH;

    $url = @parse_url($match[1]);

    $domain = explode('.', $url['host']);
    $domain = $this->checkUrl(str_replace('.', '_', $url['host']));


    //check if favicon exists locally
    if ($url['host'] AND !file_exists($dir .'/'. $domain .'.ico')) {

      //check for favicon in metatags
      /** @var \Guzzle\Http\Message\Response $result */
      try {
        $result = $this->client->get($match[1]);
      }
      catch (RequestException $e) {
        // Let's log it and return early in case the actual domain is not
        // accessible itself.
        $this->logger->info('Could not find URL %url.', ['%url' => $match[1]]);
        return $match[1];
      }

      if (preg_match('/<link[^>]+rel="(?:shortcut )?icon"[^>]+?href="([^"]+?)"/si', $result->getBody(), $icons)) {

        if (strpos($icons[1], '://')) {
          // absolute path
          try {
            $result = $this->client->get($this->checkUrl($icons[1]));
          }
          catch (RequestException $e) {
          }
        }
        else if (substr($icons[1], 0, 3) == '../') {
          // relative path
          $path = '';
          $elements = explode('/', $url['path']);
          $i = 0;
          while (isset($elements[$i]) && !strpos($elements[$i], '.') AND $i <= count($elements)) {
            $path .= $elements[$i] .'/';
            $i++;
          }

          try {
            $result = $this->client->get($this->checkUrl($url['scheme'] .'://'. $url['host'] . $path . $icons[1]));
          }
          catch (RequestException $e) {
          }
        }
        // Protocol relative URLs.
        else if (substr($icons[1], 0, 2) == '//') {
          try {
            $result = $this->client->get($this->checkUrl($url['scheme'] . ':' . $icons[1]));
          }
          catch (RequestException $e) {
          }
        }
        else if (substr($icons[1], 0, 1) == '/') {
          // relative path
          try {
            $result = $this->client->get($this->checkUrl($url['scheme'] .'://'. $url['host'] . $icons[1]));
          }
          catch (RequestException $e) {
          }
        }
        else {
          // get favicon from webroot
          try {
            $this->logger->error('Could not find favicon for URL %url with shortcut url %shortcut, trying webroot.', ['%url' => $match[1], '%shortcut' => $icons[1]]);
            $result = $this->client->get($this->checkUrl('http://'. $url['host'] .'/favicon.ico'));
          }
          catch (RequestException $e) {
            $this->logger->info('Could not find favicon for URL %url in webroot.', ['%url' => $match[1]]);
          }
        }

      }
      else {
        // get favicon from webroot
        try {
          $this->logger->info('Could not find favicon for URL %url in metatags, trying webroot.', ['%url' => $match[1]]);
          $result = $this->client->get($this->checkUrl('http://'. $url['host'] .'/favicon.ico'));
        }
        catch (RequestException $e) {
          $this->logger->info('Could not find favicon for URL %url in webroot.', ['%url' => $match[1]]);
        }
      }

      // Verify if the favicon was returned
      if ($result && ($result->getStatusCode() == 200) AND ($result->getHeader('Content-Length') > 0 OR $result->getHeader('Content-length') > 0)) {
        //check for acceptable Content-Type
        //TODO: refactor code
        $content_type_1 = explode(';', $result->getHeader('Content-Type'));
        $content_type_2 = explode(';', $result->getHeader('Content-Type'));

        if (in_array($content_type_1[0], $ui_ctype) OR in_array($content_type_2[0], $ui_ctype)) {
          //save favicon to file
          file_save_data($result->getBody(), $dir .'/'. $domain .'.ico', FILE_EXISTS_REPLACE);
        }
      }
    }

    // check for favicon availability
    $favicon = file_exists($dir .'/'. $domain .'.ico') ? (file_create_url($dir .'/'. $domain .'.ico')) : (base_path().drupal_get_path('module', 'urlicon') .'/Icon_External_Link.png');

    $build = [
      '#theme' => 'urlicon',
      '#text' => $match[3],
      '#favicon' => $favicon,
      '#path' => $match[1],
      '#attributes' => array('alt' => '', 'title' => $this->t('favicon'), 'class' => ['urlicon', 'urlicon-'. String::checkPlain($domain)]),
    ];
    return drupal_render($build);
  }

  /**
   * @todo
   */
  public function formatIcon($match) {
    $dir = UI_FILE_PATH;

    $url = @parse_url($match[1]);
    $domain = explode('.', $url['host']);
    $domain = check_url(str_replace('.', '_', $url['host']));

    // check for favicon availability
    $favicon = base_path() . drupal_get_path('module', 'urlicon') .'/Icon_External_Link.png';

    $link = theme('urlicon', array(
        'text' => $match[3],
        'favicon' => $favicon,
        'path' => $match[1],
        'attributes' => array('alt' => '', 'title' => t('favicon'), 'class' => array('urlicon',  'urlicon-'. String::checkPlain($domain))),
      ));
    return $link;
  }

  /**
   * @todo
   */
  public function formatClass($match) {
    $url = parse_url($match[1]);
    $domain = explode('.', $url['host']);
    $domain = $domain[(count($domain)-2)];

    if (stristr($match[0], 'class')) $match[0] = str_replace('class="', 'class="uc-'. String::checkPlain($domain) .' ', $match[0]);
    else $match[0] = str_replace('">', '" class="urlicon urlicon-'. String::checkPlain($domain) .'">', $match[0]);

    return $match[0];
  }

}
