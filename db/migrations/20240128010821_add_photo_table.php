<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AddPhotoTable extends AbstractMigration
{
  public function change(): void
  {
    $table= $this->table('photo');
    $table
      ->addColumn('ulid', 'char', [ 'limit' => 26, 'null' => false ])
      ->addColumn('filename', 'string', [ 'limit' => 255 ])
      ->addColumn('details', 'json')
      ->addColumn('thumbhash', 'string', [ 'limit' => 34, 'null' => true ])
      ->addColumn('name', 'string', [ 'limit' => 255, 'null' => true ])
      ->addColumn('alt_text', 'text', [
                    'limit' => MysqlAdapter::TEXT_MEDIUM
                  ])
      ->addColumn('caption', 'text', [
                    'limit' => MysqlAdapter::TEXT_MEDIUM
                  ])
      ->addColumn('privacy', 'enum', [
        'values' => [
          'public', 'friend & family', 'private'
        ],
        'default' => 'private'
      ])
      /* These next few may be redundant with info in details, but useful as
       * distinct columns */
      ->addColumn('width', 'integer', [ 'null' => false, ])
      ->addColumn('height', 'integer', [ 'null' => false, ])
      ->addColumn('rotation', 'integer', [ 'default' => 0, 'null' => false, ])
      ->addColumn('taken_at', 'datetime', [ ])
      /* Don't use ->addTimestamps() because we use DATETIME */
      ->addColumn('created_at', 'datetime', [
                    'default' => 'CURRENT_TIMESTAMP',
      ])
      ->addColumn('updated_at', 'datetime', [
                    'update' => 'CURRENT_TIMESTAMP',
                    'default' => 'CURRENT_TIMESTAMP',
      ])
      ->addIndex('ulid', [ 'unique' => true ])
      ->addIndex('taken_at')
      ->addIndex('created_at')
      ->create();

    $photo_to_tag= $this->table('photo_to_tag', [
      'id' => false,
      'primary_key' => [ 'photo_id', 'tag_id' ]
    ]);
    $photo_to_tag
      ->addColumn('photo_id', 'integer', [ 'signed' => false, 'null' => false ])
      ->addColumn('tag_id', 'integer', [ 'signed' => false, 'null' => false ])
      ->addIndex(['tag_id'])
      ->create();
  }
}
