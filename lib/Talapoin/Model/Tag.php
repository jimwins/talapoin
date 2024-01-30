<?php
namespace Talapoin\Model;

class Tag extends \Talapoin\Model {
  public function __toString() {
    return $this->name;
  }
}
