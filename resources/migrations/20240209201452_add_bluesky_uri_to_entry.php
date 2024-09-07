<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class AddBlueskyUriToEntry extends AbstractMigration
{
  public function change(): void
  {
      $table = $this->table('entry');

      $table->addColumn('bluesky_uri', 'string', [
              'length' => 255, // is this enough? who knows.
              'after' => 'mastodon_uri'
            ])
            ->update();
  }
}
