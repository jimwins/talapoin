<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AddTootToEntry extends AbstractMigration
{
    public function change(): void
    {
      $table= $this->table('entry');

      $table->addColumn('toot', 'text', [
              'after' => 'entry'
            ])
            ->update();
    }
}
