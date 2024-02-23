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

// Copy previous vendor directory
set('copy_dirs', [ 'vendor' ]);
before('deploy:vendors', 'deploy:copy_dirs');

// Tasks
after('deploy:cleanup', 'phinx:migrate');

// If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');
