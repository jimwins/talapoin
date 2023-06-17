<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixCommentIpField extends AbstractMigration
{
    public function up(): void
    {
      $table= $this->table('comment');

      $table->addColumn('ip_new', 'string', ['limit' => 64, 'null' => true, 'after' => 'ip' ])
            ->update();

      $this->execute("UPDATE comment SET ip_new = INET_NTOA(ip)");

      $table->removeColumn('ip')
            ->update();

      $table->renameColumn('ip_new', 'ip')
            ->update();
    }

    public function down(): void
    {
      $table= $this->table('comment');

      $table->addColumn('ip_new', 'integer', [
                          'signed' => false,
                          'default' => 0,
                          'after' => 'ip',
                        ])
            ->update();

      $this->execute("UPDATE comment SET ip_new = INET_ATON(ip)");

      $table->removeColumn('ip')
            ->update();

      $table->renameColumn('ip_new', 'ip')
            ->update();
    }
}
