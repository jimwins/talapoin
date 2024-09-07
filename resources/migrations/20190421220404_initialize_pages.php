<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

class InitializePages extends AbstractMigration
{
    public function change()
    {
      $page= $this->table('page');
      $page
        ->addColumn('title', 'string', [
                      'limit' => 255,
                      'null' => true,
                      'default' => ''
                    ])
        ->addColumn('slug', 'string', [ 'limit' => 255 ])
        ->addColumn('content', 'text', [
                      'null' => true,
                      'limit' => MysqlAdapter::TEXT_MEDIUM
                    ])
        ->addColumn('description', 'text', [ 'null' => true ])
        ->addColumn('draft', 'integer', [
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
        ->addIndex(['slug'], [ 'unique' => true ])
        ->create();
    }
}
