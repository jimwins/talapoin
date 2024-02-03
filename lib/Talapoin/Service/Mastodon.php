<?php

namespace Talapoin\Service;

class Mastodon {
  private $config;

  public function __construct(Config $config) {
    $this->config= @$config['mastodon'];
  }

  public function post($content) {
    if (!$this->config) {
      error_log("No Mastodon config, so not posting");
      return;
    }

    $client= new \Vazaha\Mastodon\ApiClient(new \GuzzleHttp\Client());
    $client->setBaseUri('https://' . $this->config['host']);

    if (array_key_exists('access_token', $this->config)) {
      $client->setAccessToken($this->config['access_token']);
    } else {
      error_log("Don't know how to generate access token, so not posting");
    }

    $visibility= 'public';

    $res= $client->methods()->statuses()->create(
      $content,
      visibility: $visibility
    );

    return $res;
  }
}
