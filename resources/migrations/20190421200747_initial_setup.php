<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

class InitialSetup extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    addCustomColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Any other destructive changes will result in an error when trying to
     * rollback the migration.
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change()
    {
      $entry= $this->table('entry');
      $entry
        ->addColumn('title', 'string', [ 'limit' => 255, 'null' => true ])
        ->addColumn('entry', 'text', [
                      'limit' => MysqlAdapter::TEXT_MEDIUM
                    ])
        ->addColumn('article', 'integer', [
                      'limit' => MysqlAdapter::INT_TINY,
                      'default' => 0,
                    ])
        ->addColumn('draft', 'integer', [
                      'limit' => MysqlAdapter::INT_TINY,
                      'default' => 0,
                    ])
        ->addColumn('closed', 'integer', [
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
        ->addIndex(['created_at'])
        ->addIndex(['title','entry'], [ 'type' => 'fulltext' ])
        ->create();

      $tag= $this->table('tag');
      $tag
        ->addColumn('name', 'string', ['limit' => 64])
        ->addIndex('name', [ 'unique' => true ])
        ->create();

      $entry_to_tag= $this->table('entry_to_tag', [
                       'id' => false,
                       'primary_key' => [ 'entry_id', 'tag_id' ]
                     ]);
      $entry_to_tag
        ->addColumn('entry_id', 'integer', [ 'signed' => false, 'null' => false ])
        ->addColumn('tag_id', 'integer', [ 'signed' => false, 'null' => false ])
        ->addIndex(['tag_id'])
        ->create();

      $comment= $this->table('comment');
      $comment
        ->addColumn('entry_id', 'integer', [
                      'signed' => false,
                      'default' => 0
                    ])
        ->addColumn('name', 'string', [
                      'limit' => 255,
                      'default' => ''
                    ])
        ->addColumn('email', 'string', [ 'limit' => 255, 'null' => true ])
        ->addColumn('url', 'string', [ 'limit' => 255, 'null' => true ])
        ->addColumn('title', 'string', [ 'limit' => 255, 'null' => true ])
        ->addColumn('comment', 'text', [
                      'limit' => MysqlAdapter::TEXT_MEDIUM
                    ])
        ->addColumn('ip', 'integer', [
                      'signed' => false,
                      'default' => 0,
                    ])
        ->addColumn('tb', 'integer', [
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
        ->addIndex(['entry_id'])
        ->create();
    }
}
