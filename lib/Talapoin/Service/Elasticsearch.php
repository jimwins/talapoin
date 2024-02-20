<?php
namespace Talapoin\Service;

class Elasticsearch
{
  private $client;

  public function __construct(
    private Blog $blog,
    Config $config
  ) {
    $search= $config['elasticsearch'];
    $this->client=
      \Elastic\Elasticsearch\ClientBuilder::create()
      ->setHosts(['http://elasticsearch:9200'])
      ->setBasicAuthentication($search['user'], $search['password'])
      ->build();
  }

  public function findEntries($q) {
    $params = [
      'index' => 'talapoin',
      'body' => [
        'size' => 100,
        'query' => [
          'query_string' => [
            'query' => $q,
          ]
        ]
      ],
    ];

    $results = $this->client->search($params);

    $ids= array_map(function ($e) { return $e['_id']; }, $results['hits']['hits']);

    if (!$ids) return [];

    return
      $this->blog->getEntries()
        ->where_in('id', $ids)
        ->order_by_desc('created_at')
        ->find_many();
  }

  public function reindex($id= null) {
    $entries=
      $this->blog->getEntries()
        ->order_by_asc('created_at');

    if ($id) {
      $entries= $entries->where('id', $id);
    }

    $entries= $entries->find_many();

    if ($id) {
      $response = $this->client->delete([
        'index' => 'talapoin',
        'id' => $id,
      ]);
    } else {
      /* Just delete and re-create the index. YOLO! */
      $params = [
        'index' => 'talapoin',
      ];
      try {
        $response = $this->client->indices()->delete($params);
      } catch (\Exception $e) {
        error_log("failed to delete index: " . (string)$e);
      }
      $response = $this->client->indices()->create($params);
    }

    $rows= 0;
    foreach ($entries as $entry) {
      $response = $this->client->index([
        'index' => 'talapoin',
        'id' => $entry->id,
        'timestamp' => strtotime($entry->created_at),
        'body' => [
          'title' => $entry->title,
          'entry' => $entry->entry,
          'tags' => $entry->tags_json ? join(' ', json_decode($entry->tags_json)) : "",
        ],
      ]);
      $rows++;
    }

    return $rows;
  }
}
