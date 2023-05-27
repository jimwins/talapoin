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

    $api= new \Fundevogel\Mastodon\Api($this->config['host']);

    if (array_key_exists('access_token', $this->config)) {
      $api->accessToken= $this->config['access_token'];
    } else {
      $api->accessToken= $api->oauth()->token($this->config['client_key'], $this->config['client_secret'])->accessToken();
    }

    $api->logIn();

    $status= [
      'status' => $content,
#      'visibility' => 'private',
    ];

    $endpoint= "statuses";
    $res= $api->post($endpoint, $status);
  }
}
