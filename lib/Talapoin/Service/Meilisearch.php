<?php
namespace Talapoin\Service;

class Meilisearch
{
  private $client;

  public function __construct(
    private Blog $blog,
    Config $config
  ) {
    $search= $config['meilisearch'];
    $this->client= new \Meilisearch\Client(
      'http://meilisearch:7700',
      $search['search_key']
    );
  }

  public function findEntries($q) {
    $index = $this->client->index('talapoin');

    $results = $index->search($q)->getHits();

    $ids= array_map(function ($e) { return $e['id']; }, $results);

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

    $index = $this->client->index('talapoin');

    if ($id) {
      $response = $index->deleteDocument($id);
    } else {
      /* Just delete and re-create the index. YOLO! */
      try {
        $response = $index->deleteAllDocuments();
      } catch (\Exception $e) {
        error_log("failed to delete index: " . (string)$e);
      }
    }

    $documents = array_map(function ($entry) {
      return [
        'id' => $entry->id,
        'title' => $entry->title,
        'entry' => $entry->entry,
        'tags' => $entry->tags_json ? json_decode($entry->tags_json) : [],
      ];
    }, $entries);

    $res = $index->addDocuments($documents);

    // TODO figure out how many were queued?

    return 0;
  }
}
