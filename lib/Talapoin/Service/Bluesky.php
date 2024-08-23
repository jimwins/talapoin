<?php

declare(strict_types=1);

namespace Talapoin\Service;

class Bluesky
{
    private $config;
    private $client;

    public function __construct(Config $config)
    {
        $this->config = @$config['bluesky'];
    }

    protected function getClient()
    {
        if (!isset($this->client)) {
            $this->client = new \cjrasmussen\BlueskyApi\BlueskyApi();
            $this->client->auth($this->config['handle'], $this->config['password']);
        }

        return $this->client;
    }

    public function post(string $content, string $url)
    {
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

        // Add facets for hashtags
        preg_match_all('/\b(#\w+)\b/', $content, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as $match) {
            $args['records']['facets'][] = [
                'index' => [
                    'byteStart' => $match[1] - 1,
                    'byteEnd' => $match[1] + strlen($match[0]) - 1,
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#tag',
                        'tag' => preg_replace('/^#/', '', $match[0]),
                    ],
                ],
            ];
        }

        return $client->request('POST', 'com.atproto.repo.createRecord', $args);
    }

    public function getRecord($uri)
    {
        if (!$this->config) {
            error_log("No Bluesky config, so not handling");
            return [];
        }

        $client = $this->getClient();

        return $client->request(
            'GET',
            'app.bsky.feed.getPostThread',
            [ 'uri' => $uri, 'depth' => 0 ]
        );
    }
}
