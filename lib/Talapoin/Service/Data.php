<?php
namespace Talapoin\Service;

class Data
{
  private $dsn, $options;

  public function __construct($config) {
    $this->dsn= 'mysql:host=' . $config['db']['host'] . ';' .
                'dbname=' . $config['db']['dbname'];

    $this->options= [
      'username' => $config['db']['user'],
      'password' => $config['db']['pass'],
    ];

    /* Configure Titi */
    \Titi\ORM::configure($this->dsn);
    foreach ($this->options as $option => $value) {
      \Titi\ORM::configure($option, $value);
    }
    \Titi\ORM::configure('driver_options', [
      \PDO::MYSQL_ATTR_LOCAL_INFILE => true,
    ]);

    /* Always want to throw exceptions for errors */
    \Titi\ORM::configure('error_mode', \PDO::ERRMODE_EXCEPTION);

    /* ... and Paris */
    \Titi\Model::$auto_prefix_models= '\\Talapoin\\Model\\';
    \Titi\Model::$short_table_names= true;

    if (array_key_exists('debug', $config['db']) && $config['db']['debug']) {
      \Titi\ORM::configure('logging', true);
      \Titi\ORM::configure('logger', function ($log_string, $query_time) {
        error_log('ORM: "' . $log_string . '" in ' . $query_time . "\n");
      });
    }
  }

  public function beginTransaction() {
    return \Titi\ORM::get_db()->beginTransaction();
  }

  public function commit() {
    return \Titi\ORM::get_db()->commit();
  }

  public function rollback() {
    return \Titi\ORM::get_db()->rollback();
  }

  public function factory($name) {
    return \Titi\Model::factory($name);
  }

  public function configure($name, $value) {
    return \Titi\ORM::configure($name, $value);
  }

  public function for_table($name) {
    return \Titi\ORM::for_table($name);
  }

  public function execute($query, $params= []) {
    return \Titi\ORM::raw_execute($query, $params);
  }

  public function get_last_statement() {
    return \Titi\ORM::get_last_statement();
  }

  public function escape($value) {
    return \Titi\ORM::get_db()->quote($value);
  }

  public function fetch_single_value($query, $params= []) {
    if (\Titi\ORM::raw_execute($query, $params)) {
      $stmt= \Titi\ORM::get_last_statement();
      return $stmt->fetchColumn();
    }
    return false;
  }

  public function fetch_all($query, $params= []) {
    if (\Titi\ORM::raw_execute($query, $params)) {
      $stmt= \Titi\ORM::get_last_statement();
      return $stmt->fetchAll();
    }
    return false;
  }

  public function get_lock($name, $timeout= 5) {
    return $this->fetch_single_value(
      "SELECT GET_LOCK(?,?)",
      [ $name, $timeout ]
    );
  }
}
