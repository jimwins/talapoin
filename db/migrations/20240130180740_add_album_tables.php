<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AddAlbumTables extends AbstractMigration
{
  public function change(): void
  {
    $album= $this->table('album');
    $album
      ->addColumn('name', 'string', [ 'limit' => 64, 'null' => false ])
      ->addColumn('title', 'string', [ 'limit' => 255, 'null' => true ])
      ->addColumn('description', 'text', [
                    'limit' => MysqlAdapter::TEXT_MEDIUM
                  ])
      ->addColumn('privacy', 'enum', [
        'values' => [
          'public', 'friend & family', 'private'
        ],
        'default' => 'private'
      ])
      ->addColumn('cover_photo_id', 'integer', [ 'signed' => false ])
      /* Don't use ->addTimestamps() because we use DATETIME */
      ->addColumn('created_at', 'datetime', [
                    'default' => 'CURRENT_TIMESTAMP',
      ])
      ->addColumn('updated_at', 'datetime', [
                    'update' => 'CURRENT_TIMESTAMP',
                    'default' => 'CURRENT_TIMESTAMP',
      ])
      ->addIndex('name')
      ->addIndex('created_at')
      ->create();

    $photo_to_album= $this->table('photo_to_album', [
      'id' => false,
      'primary_key' => [ 'photo_id', 'album_id' ]
    ]);
    $photo_to_album
      ->addColumn('photo_id', 'integer', [ 'signed' => false, 'null' => false ])
      ->addColumn('album_id', 'integer', [ 'signed' => false, 'null' => false ])
      ->addColumn('position', 'integer', [ 'signed' => false, 'default' => 0, 'null' => false ])
      ->addIndex(['album_id'])
      ->create();
  }
}
