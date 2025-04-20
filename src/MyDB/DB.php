<?php

namespace DRLib\MyDB;

class DB {
    public $dbh;

    function __construct() {

        try {
            $this->dbh = new \PDO("mysql:dbname=" . $_ENV["db_name"] . ";host=" . $_ENV["db_host"], $_ENV["db_user"], $_ENV["db_pass"]);
        } catch (\Exception $e) {
            if (isset($_ENV['debug']) && $_ENV['debug'] === "true") {
                throw $e;
            } else {
                throw new \Exception("Uppsie daisy!", 1);
            }
            die();
        }
    }
}
