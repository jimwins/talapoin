<?php
namespace Talapoin\Controller;

use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;

class Blog {
  public function __construct(
    private \Talapoin\Service\Data $data,
    private View $view
  ) {
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
