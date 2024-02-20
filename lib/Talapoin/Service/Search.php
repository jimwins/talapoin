<?php
namespace Talapoin\Service;

class Search
{
  private $pdo;

  public function __construct(
    private Blog $blog,
    Config $config
  ) {
    $search= $config['search'];
    $this->pdo= new \PDO($search['dsn'], $search['user'], $search['password']);
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
  }

  public function findEntries($q) {
    $query= "SELECT id FROM talapoin WHERE MATCH(?)";
    $stmt= $this->pdo->prepare($query);

    $stmt->execute([$q]);

    $ids= array_map(function ($e) { return $e['id']; }, $stmt->fetchAll());

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
      $this->pdo->query("DELETE FROM talapoin WHERE id = $id");
    } else {
      $this->pdo->query("DELETE FROM talapoin WHERE id > 0");
    }

    $query= "INSERT INTO talapoin (id, title, content, created_at, tags)
             VALUES (?, ?, ?, ?, ?)";
    $stmt= $this->pdo->prepare($query);

    $rows= 0;
    foreach ($entries as $entry) {
      $stmt->execute([
        $entry->id,
        $entry->title,
        $entry->entry,
        $entry->created_at,
        $entry->tags_json ? join(' ', json_decode($entry->tags_json)) : ""
      ]);
      $rows+= $stmt->rowCount();
    }

    return $rows;
  }
}
