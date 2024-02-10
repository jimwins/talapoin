<?php
namespace Talapoin\Controller;

use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;

class Page {
  public function __construct(
    private \Talapoin\Service\Page $page,
    private \Talapoin\Service\Data $data,
    private View $view
  ) {
  }

  public function showPage(Request $request, Response $response, string $path) {
    if (str_starts_with($path, '@')) {
      throw new \Slim\Exception\HttpForbiddenException($request, "Nothing to see here.");
    }

    /* Trailing slash? Might need to redirect to page */
    if (str_ends_with($path, '/')) {
      $path = substr($path, 0, -1);
      $page = $this->page->getPageBySlug($path);
      if ($page) {
        return $response->withRedirect($path);
      }
    } else {
      $page = $this->page->getPageBySlug($path);

      if ($page) {
        return $this->view->render($response, 'page.html', [ 'page' => $page ]);
      }
    }

    // check for redirects
    $query = "SELECT source, dest FROM redirect WHERE ? LIKE source";
    if (($redir = $this->data->fetch_single_row($query, [ $path ])) {
      if (($pos = strpos($redir['source'], '%'))) {
        $dest = $redir['dest'] . substr($path, $pos);
      } else {
        $dest = $redir['dest'];
      }
      return $response->withRedirect($dest);
    }

    throw new \Slim\Exception\HttpNotFoundException($request);
  }
}
