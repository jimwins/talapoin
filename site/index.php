<?php

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require '../vendor/autoload.php';

$config= parse_ini_file('../config.ini', TRUE, INI_SCANNER_TYPED);

$app= new \Slim\App([ 'settings' => $config ]);

$container= $app->getContainer();

/* We use monolog for logging (but still just through PHP's log for now) */
$container['logger']= function($c) {
  $logger= new \Monolog\Logger('talapoin');
  $handler= new \Monolog\Handler\ErrorLogHandler();
  $logger->pushHandler($handler);
  return $logger;
};

/* PDO */
$container['db']= function ($c) {
  $db= $c['settings']['db'];
  $pdo= new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['dbname'],
                $db['user'], $db['pass']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return $pdo;
};

/* Twig for templating */
$container['view']= function ($container) {
  $view= new \Slim\Views\Twig('../ui', [
    'cache' => false /* No cache for now */
  ]);

  // Instantiate and add Slim specific extension
  $basePath= $container->get('request')->getUri()->getBasePath();
  $basePath= rtrim(str_ireplace('index.php', '', $basePath), '/');

  $view->addExtension(new Slim\Views\TwigExtension($container->get('router'),
                                                   $basePath));

  return $view;
};

/* Add filters for blog entries */
$filter= new Twig_SimpleFilter('expand_psuedo_urls', function ($text) {
  $text= preg_replace('/isbn:([0-9x]+)/i',
                      'http://www.amazon.com/exec/obidos/ASIN/$1/trainedmonkey',
                      $text);
  $text= preg_replace('/asin:(\w+)/i',
                      'http://www.amazon.com/exec/obidos/ASIN/$1/trainedmonkey',
                      $text);
  return $text;
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new Twig_SimpleFilter('paragraphs', function ($text) {
  return preg_replace('!\n\n!', '</p><p>', $text);
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new Twig_SimpleFilter('prettify_markup', function ($text) {
  $text= preg_replace('!<q>!', '&ldquo;', $text);
  $text= preg_replace('!</q>!', '&rdquo;', $text);
  return $text;
});
$container->get('view')->getEnvironment()->addFilter($filter);

$filter= new Twig_SimpleFilter('slug', function ($text) {
  return preg_replace('/[^-A-Za-z0-9,]/u', '_', $text);
});
$container->get('view')->getEnvironment()->addFilter($filter);

/* A single entry */
$app->get('/{year:[0-9]+}[/{month:[0-9]+}[/{day:[0-9]+}[/{slug}]]]',
          function (Request $req, Response $res, array $args) {

$year=  (int)$args['year'];
$month= sprintf("%02d", (int)$args['month']);
$day=   sprintf("%02d", (int)$args['day']);
$id=    $args['slug'];

if (is_numeric($id)) {
  $where= "id = $id";
} else {
  $where= "(DATE(created_at) = '$year-$month-$day'
            OR DATE(created_at) = ('$year-$month-$day' + INTERVAL 1 DAY))
           AND title LIKE '" . addslashes($id) . "'";
}

$entry= get_entry($this->db, $where);

/* Get next/previous */
$previous= get_entry($this->db, "created_at < '{$entry['created_at']}'", "DESC");
$next= get_entry($this->db, "created_at > '{$entry['created_at']}'", "ASC");

/* Get comments */
$comments= [];
if ($entry['comments']) {
  $query=
  " SELECT id, name, email, url, title, comment,
         INET_NTOA(ip) AS ip,
         UNIX_TIMESTAMP(created_at) AS created_at
    FROM comment
   WHERE entry_id = ? AND NOT tb
   ORDER BY created_at ASC
  ";

  $sth= $this->db->prepare($query);
  $sth->execute([$entry['id']]);

  $comments= $sth->fetchAll();
}

return $this->view->render($res, 'entry.html', [ 'entry' => $entry,
                                                 'next' => $next,
                                                 'previous' => $previous,
                                                 'comments' => $comments ]);

          })->setName('entry');

$app->get('/',
          function (Request $req, Response $res, array $args) {

$where= "";
$order= "DESC";
$limit= "LIMIT 12";

$query=
" SELECT id, title, entry, closed, created_at, updated_at, article,
         (SELECT JSON_ARRAYAGG(name)
            FROM entry_to_tag, tag
           WHERE entry_id = entry.id AND tag_id = tag.id) AS tags,
         (SELECT COUNT(*)
            FROM comment
           WHERE entry_id = entry.id AND NOT tb) AS comments
    FROM entry
   WHERE NOT draft $where
   ORDER BY created_at $order
   $limit
";

$stmt= $this->db->query($query);

$entries= [];
while (($entry= $stmt->fetch())) {
  $entry['tags']= json_decode($entry['tags']);
  $entries[]= $entry;
}

return $this->view->render($res, 'index.html', [ 'entries' => $entries ]);

          })->setName('entry');


$app->get('/tag/{tag}',
          function (Request $req, Response $res, array $args) {
          })->setName('tag');

$app->get('/hello/{name}', function (Request $request, Response $response, array $args) {
    $name= $args['name'];
    $response->getBody()->write("Hello, $name");

    $this->logger->addInfo('Something interesting happened');

    return $response;
});

$app->get('[/{slug:.*}]', function (Request $req, Response $res, array $args) {
  $slug= preg_replace('!/$!', '', $args['slug']); # trim trailing /
  $query= "SELECT * FROM page WHERE slug = ?";
  $stmt= $this->db->prepare($query);
  if ($stmt->execute([$slug]) && $stmt->rowCount()) {
    $page= $stmt->fetch(\PDO::FETCH_ASSOC);
    return $this->view->render($res, 'page.html', [ 'page' => $page ]);
  }
  throw new \Slim\Exception\NotFoundException($req, $res);
});

$app->run();

function get_entry($db, $where, $order= 'ASC') {
  $query=
  " SELECT id, title, entry, closed, created_at, updated_at, article,
           (SELECT JSON_ARRAYAGG(name)
              FROM entry_to_tag, tag
             WHERE entry_id = entry.id AND tag_id = tag.id) AS tags,
           (SELECT COUNT(*)
              FROM comment
             WHERE entry_id = entry.id AND NOT tb) AS comments
      FROM entry
     WHERE $where AND NOT draft
     ORDER BY id $order
  ";

  $stmt= $db->query($query);

  $entry= $stmt->fetch();
  $entry['tags']= json_decode($entry['tags']);

  return $entry;
}
