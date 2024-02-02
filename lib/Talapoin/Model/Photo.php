<?php
namespace Talapoin\Model;

use Thumbhash\Thumbhash;

class Photo extends \Talapoin\Model {
  protected $tags_model= 'PhotoTag';
  use HasTags;

  public function slug($string) {
    return preg_replace('/[^-A-Za-z0-9,]/u', '_', strtolower($string));
  }

  public function canonicalUrl() {
    return '/photo/' . $this->ulid . ($this->name ? '_' . $this->slug($this->name) : '');
  }


  public function albums($new_tags= null) {
    return $this->_handle_tags('Album', 'PhotoAlbum', $new_tags);
  }

  public function thumbHashDimensions() {
    $data= Thumbhash::hashToRGBA(Thumbhash::convertStringToHash($this->thumbhash));
    return [
      'width' => $data['w'],
      'height' => $data['h'],
    ];
  }

  public function thumbHashDataUrl() {
    return Thumbhash::toDataURL(Thumbhash::convertStringToHash($this->thumbhash));
  }

  public function imgUrl($options= []) {
    return $GLOBALS['container']->get(\Talapoin\Service\Gumlet::class)->getSignedUrl(
      $this->filename, $options
    );
  }
}

class PhotoTag extends \Talapoin\Model {
  public static $_table= 'photo_to_tag';
}
