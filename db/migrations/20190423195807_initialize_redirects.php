<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

class InitializeRedirects extends AbstractMigration
{
    public function change()
    {
      $redirect= $this->table('redirect');
      $redirect
        ->addColumn('source', 'string', [ 'limit' => 255 ])
        ->addColumn('dest', 'string', [ 'limit' => 255 ])
        /* Don't use ->addTimestamps() because we use DATETIME */
        ->addColumn('created_at', 'datetime', [
                      'default' => 'CURRENT_TIMESTAMP',
                    ])
        ->addColumn('updated_at', 'datetime', [
                      'update' => 'CURRENT_TIMESTAMP',
                      'default' => 'CURRENT_TIMESTAMP',
                    ])
        ->addIndex(['source'], [ 'unique' => true ])
        ->create();
    }
}
