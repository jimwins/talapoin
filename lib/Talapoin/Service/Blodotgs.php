<?php

namespace Talapoin\Service;

class Blodotgs {
  private $config;

  public function __construct(Config $config) {
    $this->config= @$config['blo.gs'];
  }

  public function ping($url, $name, $feed= null) {
    if (empty($this->config) || !array_key_exists('host', $this->config)) {
      error_log("skipping ping, don't have host");
      return;
    }

    $client= new \GuzzleHttp\Client([
      'base_uri' => $this->config['host'],
    ]);

    $query= [
      'url' => $url,
      'name' => $name,
      'direct' => 1
    ];
    if ($feed) {
      $query['feed']= $feed;
    }

    $response= $client->get('/ping.php', [ 'query' => $query ]);
    /* We don't actually pay attention to the response, because we don't care. */
  }
}
