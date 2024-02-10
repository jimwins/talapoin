<?php

namespace Talapoin\Service;

class Bluesky {
  private $config;
  private $client;

  public function __construct(Config $config) {
    $this->config= @$config['bluesky'];
  }

  protected function getClient() {
    if (!isset($this->client)) {
      $this->client = new \cjrasmussen\BlueskyApi\BlueskyApi();
      $this->client->auth($this->config['handle'], $this->config['password']);
    }

    return $this->client;
  }

  public function post(string $content, string $url) {
    if (!$this->config) {
      error_log("No Bluesky config, so not posting");
      return;
    }

    $client = $this->getClient();

    $args = [
      'collection' => 'app.bsky.feed.post',
      'repo' => $client->getAccountDid(),
      'record' => [
        'text' => $content . ' ' . $url,
        'langs' => [ 'en' ],
        'createdAt' => date('c'),
        '$type' => 'app.bsky.feed.post',
      ],
    ];

    // turn URL into a facet so it is clicky
    $args['record']['facets'] = [
      [
        'index' => [
          'byteStart' => strlen($content) + 1,
          'byteEnd' => strlen($content) + 1 + strlen($url),
        ],
        'features' => [
          [
            '$type' => 'app.bsky.richtext.facet#link',
            'uri' => $url,
          ],
        ],
      ]
    ];

    return $client->request('POST', 'com.atproto.repo.createRecord', $args);
  }

  function getRecord($uri) {
    if (!$this->config) {
      error_log("No Bluesky config, so not handling");
      return [];
    }

    $client = $this->getClient();

    return $client->request('GET', 'app.bsky.feed.getPostThread', [ 'uri' => $uri, 'depth' => 0 ]);
  }
}
