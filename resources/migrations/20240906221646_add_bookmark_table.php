<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AddBookmarkTable extends AbstractMigration
{
  public function change(): void
  {
    $table= $this->table('bookmark');
    $table
      ->addColumn('ulid', 'char', [ 'limit' => 26, 'null' => false ])
      ->addColumn('href', 'string', [ 'limit' => 512 ])
      ->addColumn('title', 'string', [ 'limit' => 255, 'null' => true ])
      ->addColumn('excerpt', 'text', [
                    'limit' => MysqlAdapter::TEXT_MEDIUM
                  ])
      ->addColumn('comment', 'text', [
                    'limit' => MysqlAdapter::TEXT_MEDIUM
                  ])
      ->addColumn('to_read', 'integer', [
                    'limit' => MysqlAdapter::INT_TINY,
                    'default' => 0,
                  ])
      /* Don't use ->addTimestamps() because we use DATETIME */
      ->addColumn('created_at', 'datetime', [
                    'default' => 'CURRENT_TIMESTAMP',
      ])
      ->addColumn('updated_at', 'datetime', [
                    'update' => 'CURRENT_TIMESTAMP',
                    'default' => 'CURRENT_TIMESTAMP',
      ])
      ->addIndex('ulid', [ 'unique' => true ])
      ->addIndex('created_at')
      ->create();

    $bookmark_to_tag= $this->table('bookmark_to_tag', [
      'id' => false,
      'primary_key' => [ 'bookmark_id', 'tag_id' ]
    ]);
    $bookmark_to_tag
      ->addColumn('bookmark_id', 'integer', [ 'signed' => false, 'null' => false ])
      ->addColumn('tag_id', 'integer', [ 'signed' => false, 'null' => false ])
      ->addIndex(['tag_id'])
      ->create();
  }
}
