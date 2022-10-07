<?php
namespace Deployer;

require 'recipe/composer.php';
require 'contrib/phinx.php';

// Project name
set('application', 'talapoin');

// Project repository
set('repository', 'https://github.com/jimwins/talapoin.git');

// Host(s)
import('hosts.yml');

// Tasks
after('deploy:cleanup', 'phinx:migrate');

// If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');
