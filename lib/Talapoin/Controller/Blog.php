<?php
namespace Talapoin\Controller;

use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;

class Blog {
  public function __construct(
    private \Talapoin\Service\Blog $blog,
    private View $view
  ) {
  }

  public function top(Response $response) {
    $entries=
      $this->blog->getEntries()
        ->order_by_desc('created_at')
        ->limit(12)
        ->find_many();
    return $this->view->render($response, 'index.html', [ 'entries' => $entries ]);
  }

  public function atomFeed(Response $response) {
    $entries=
      $this->blog->getEntries()
        ->order_by_desc('created_at')
        ->limit(15)
        ->find_many();

    return $this->view
      ->render($response, 'index.atom', [ 'entries' => $entries ])
      ->withHeader('Content-Type', 'application/atom+xml');
  }

  public function search(Request $request, Response $response, \Talapoin\Service\Search $search) {
    $q= $request->getParam('q');

    $entries= $search->findEntries($q);

    return $this->view->render($response, 'search.html', [
      'q' => $q,
      'entries' => $entries,
    ]);
  }

  public function reindex(Response $response, \Talapoin\Service\Search $search) {
    $count= $search->reindex();
    $response->getBody()->write("Indexed $count rows.");
    return $response;
  }
}
