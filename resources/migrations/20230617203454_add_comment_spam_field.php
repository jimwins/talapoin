<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AddCommentSpamField extends AbstractMigration
{
    public function change(): void
    {
      $table= $this->table('comment');

      $table->addColumn('spam', 'integer', [
              'limit' => MysqlAdapter::INT_TINY,
              'default' => 0,
              'after' => 'tb'
            ])
            ->update();
    }
}
