<?php
namespace Talapoin\Handler;

use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

use \Slim\Views\Twig as View;

class ErrorRenderer implements ErrorRendererInterface {
  public function __construct(private View $view) {
  }

  public function __invoke(Throwable $exception, bool $displayErrorDetails): string
  {
    $template= $this->view->getEnvironment()->load('error.html');
    return $template->render([ 'exception' => $exception, 'detailed' => $displayErrorDetails ]);
  }
}
