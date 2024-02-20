<?php

namespace Talapoin\Service;

/* This just wraps an array so we can use DI to access it. */

class Config implements \ArrayAccess, \JsonSerializable {
  public function __construct(private array $config) {
  }

  public function offsetExists(mixed $offset): bool {
    return array_key_exists($offset, $this->config);
  }

  public function offsetGet(mixed $offset): mixed {
    return $this->config[$offset];
  }

  public function offsetSet(mixed $offset, mixed $value): void {
    $this->config[$offset]= $value;
  }

  public function offsetUnset(mixed $offset): void {
    unset($this->config[$offset]);
  }

  public function jsonSerialize() : mixed {
    return $this->config;
  }
}
