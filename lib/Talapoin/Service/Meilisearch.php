<?php

declare(strict_types=1);

namespace Talapoin\Service;

class Meilisearch
{
    private $client;

    public function __construct(
        private Blog $blog,
        private PhotoLibrary $photos,
        private BookmarkLibrary $bookmarks,
        Config $config
    ) {
        $search = $config['meilisearch'];
        $this->client = new \Meilisearch\Client(
            'http://meilisearch:7700',
            $search['search_key']
        );
    }

    public function findEntries($q)
    {
        $index = $this->client->index('talapoin');

        $results = $index->search($q, [
            'filter' => 'type = "entry"'
        ])->getHits();

        $ids = array_map(function ($e) {
            // Chop off the leading type
            return substr($e['id'], strlen('entry_'));
        }, $results);

        if (!$ids) {
            return [];
        }

        return
            $this->blog->getEntries()
                ->where_in('id', $ids)
                ->order_by_desc('created_at')
                ->find_many();
    }

    public function findPhotos($q)
    {
        $index = $this->client->index('talapoin');

        $results = $index->search($q, [
            'filter' => 'type = "photo"'
        ])->getHits();

        $ids = array_map(function ($e) {
            // Chop off the leading photo_
            return substr($e['id'], strlen('photo_'));
        }, $results);

        if (!$ids) {
            error_log("Nothing found for '$q'");
            return [];
        }

        return
            $this->photos->getPhotos()
                ->where_in('id', $ids)
                ->order_by_desc('created_at')
                ->find_many();
    }

    public function findBookmarks($q)
    {
        $index = $this->client->index('talapoin');

        $results = $index->search($q, [
            'filter' => 'type = "bookmark"'
        ])->getHits();

        $ids = array_map(function ($e) {
            // Chop off the leading bookmark_
            return substr($e['id'], strlen('bookmark_'));
        }, $results);

        if (!$ids) {
            error_log("Nothing found for '$q'");
            return [];
        }

        return
            $this->bookmarks->getBookmarks()
                ->where_in('id', $ids)
                ->order_by_desc('created_at')
                ->find_many();
    }

    public function reindex($id = null)
    {
        if (!$id) {
            $index = $this->client->index('talapoin');
            $response = $index->deleteAllDocuments();
        }
        $total= $this->reindexEntries($id);
        $total+= $this->reindexPhotos($id);
        $total+= $this->reindexBookmarks($id);
        return $total;
    }

    public function reindexEntries($id = null)
    {
        $entries =
            $this->blog->getEntries()
                ->order_by_asc('created_at');

        if ($id) {
            $entries = $entries->where('id', $id);
        }

        $entries = $entries->find_many();

        $index = $this->client->index('talapoin');

        if ($id) {
            $response = $index->deleteDocument('entry_' . $id);
        } else {
            /* Just delete and re-create the index. YOLO! */
            try {
                $response = $index->deleteDocuments(['filter' => 'type = "entry"']);
            } catch (\Exception $e) {
                error_log("failed to delete index: " . (string)$e);
            }
        }

        // We will want to filter on the type
        $index->updateFilterableAttributes(['type']);

        $documents = array_map(function ($entry) {
            return [
                'id' => 'entry_' . $entry->id,
                'title' => $entry->title,
                'entry' => $entry->entry,
                'tags' => $entry->tags_json ? json_decode($entry->tags_json) : [],
                'type' => 'entry',
            ];
        }, $entries);

        $res = $index->addDocuments($documents);

        return count($documents);
    }

    public function reindexPhotos($id = null)
    {
        $entries =
            $this->photos->getPhotos(page_size: 0)
                ->order_by_asc('created_at');

        if ($id) {
            $entries = $entries->where('id', $id);
        }

        $entries = $entries->find_many();

        $index = $this->client->index('talapoin');

        if ($id) {
            $response = $index->deleteDocument('photo_' . $id);
        } else {
            /* Just delete and re-create the index. YOLO! */
            try {
                $response = $index->deleteDocuments(['filter' => 'type = "photo"']);
            } catch (\Exception $e) {
                error_log("failed to delete index: " . (string)$e);
            }
        }

        $documents = array_map(function ($entry) {
            return [
                'id' => 'photo_' . $entry->id,
                'name' => $entry->name,
                'alt_text' => $entry->alt_text,
                'caption' => $entry->caption,
                'tags' => $entry->tags_json ? json_decode($entry->tags_json) : [],
                'type' => 'photo',
            ];
        }, $entries);

        $res = $index->addDocuments($documents);

        return count($documents);
    }

    public function reindexBookmarks($id = null)
    {
        $entries =
            $this->bookmarks->getBookmarks(page_size: 0)
                ->order_by_asc('created_at');

        if ($id) {
            $entries = $entries->where('id', $id);
        }

        $entries = $entries->find_many();

        $index = $this->client->index('talapoin');

        if ($id) {
            $response = $index->deleteDocument('bookmark_' . $id);
        } else {
            /* Just delete and re-create the index. YOLO! */
            try {
                $response = $index->deleteDocuments(['filter' => 'type = "bookmark"']);
            } catch (\Exception $e) {
                error_log("failed to delete index: " . (string)$e);
            }
        }

        $documents = array_map(function ($entry) {
            return [
                'id' => 'bookmark_' . $entry->id,
                'title' => $entry->title,
                'excerpt' => $entry->excerpt,
                'comment' => $entry->comment,
                'tags' => $entry->tags_json ? json_decode($entry->tags_json) : [],
                'type' => 'bookmark',
            ];
        }, $entries);

        $res = $index->addDocuments($documents);

        return count($documents);
    }
}
