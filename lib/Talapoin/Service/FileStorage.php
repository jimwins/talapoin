<?php

namespace Talapoin\Service;

/* TODO This should be an interface and we should support different back-ends.
 * */

class FileStorage {
  private $config;

  public function __construct(Config $config)
  {
    $this->config= @$config['b2'];
  }

  protected function getClient()
  {
    $id= $this->config['keyID'];
    $key= $this->config['applicationKey'];
    return \Aws\S3\S3Client::factory([
      'endpoint' => 'https://s3.us-west-001.backblazeb2.com',
      'region' => 'us-west-001',
      'version' => 'latest',
      'credentials' => [
        'key' => $id,
        'secret' => $key,
      ],
    ]);
  }

  protected function getBucketName()
  {
    return $this->config['bucketName'];
  }

  public function uploadFile($path, $stream)
  {
    $client= $this->getClient();
    $bucket= $this->getBucketName();

    error_log("Uploading to '{$path}' in bucket '{$bucket}'");

    // remove leading / or things get weird
    $path = preg_replace('!^/!', '', $path);

    $upload= $client->upload($bucket, $path, $stream);

    return $upload;
  }
}
