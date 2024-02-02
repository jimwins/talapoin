<?php

namespace Talapoin\Controller;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response as Response;
use Slim\Views\Twig as View;

class PhotoLibrary
{
  public function __construct(
    private \Talapoin\Service\PhotoLibrary $library,
    private \Talapoin\Service\Data $data,
    private \Talapoin\Service\Gumlet $gumlet,
  ) {
  }

  public static function registerRoutes(\Slim\Routing\RouteCollectorProxy $app)
  {
    $app->get('', [ self::class, 'top' ])->setName('photos');
    $app->post('', [ self::class, 'addPhoto' ])
      ->add($app->getContainer()->get(\Talapoin\Middleware\Auth::class));
    $app->get('/{ulid:[^_]*}[_{slug:.*}]', [ self::class, 'showPhoto' ])->setName('photo');
  }

  public function top(Request $request, Response $response, View $view)
  {
    $q = $request->getParam('q');
    $page = (int) $request->getParam('page') ?: 0;
    $page_size = (int) $request->getParam('page_size') ?: 24;

    $photos = $this->library->findPhotos(q: $q, page: $page, page_size: $page_size);

    return $view->render($response, 'photo/index.html', [
      'query_params' => $request->getParams(),
      'photos' => $photos,
      'q' => $q,
      'page' => $page,
      'page_size' => $page_size,
    ]);
  }

  public function showPhoto(Request $request, Response $response, View $view, $ulid)
  {
    $photo= $this->library->getPhotoByUlid($ulid);
    if (!$photo)
      throw new \Slim\Exception\HttpNotFoundException($request);

    return $view->render($response, 'photo/photo.html', [
      'photo' => $photo,
    ]);
  }

  public function addPhoto(Request $request, Response $response)
  {
    $method = $request->getParam('method');

    switch ($method) {
      case 'flickrJson':
        return $this->addPhotoFromFlickrJson($request, $response);
      default:
        throw new \Exception("Don't know how to handle that method.");
    }
  }

  public function addPhotoFromFlickrJson(Request $request, Response $response)
  {
    $fn = $request->getParam('fn');

    if ($request->getUploadedFiles()) {
      $file = $request->getUploadedFiles()['flickr'];

      $flickr = json_decode($file->getStream(), flags: \JSON_THROW_ON_ERROR);

      // Cheat here because Flickr doesn't have time down to milliseconds
      $ts = (new \DateTime($flickr->date_imported))->getTimestamp() * 1000;

      $details = $this->gumlet->getImageDetails($fn);
      $thumbhash = $this->gumlet->getThumbHash($fn);

      $this->data->beginTransaction();

      $photo = $this->library->createPhoto();
      $photo->ulid = \Ulid\Ulid::fromTimestamp($ts, true);
      $photo->filename = $fn;
      $photo->details = json_encode($details);
      $photo->thumbhash = $thumbhash;
      $photo->name = $flickr->name;
      $photo->caption = $flickr->description;
      $photo->privacy = $flickr->privacy;
      $photo->width = $details->width;
      $photo->height = $details->height;
      $photo->rotation = $flickr->rotation;
      $photo->taken_at = (new \DateTime($flickr->date_taken))->format('Y-m-d H:i:s');

      $tags = array_map(function ($i) {
        return $i->tag;
      }, $flickr->tags);
      $photo->tags($tags);

      $albums = array_map(function ($i) {
        return $i->id;
      }, $flickr->albums);

      $photo->albums($albums);

      $photo->save();

      $this->data->commit();

      return $response->withJson($photo);
    } else {
      throw new \Exception("Expected Flickr JSON data");
    }
  }
}
