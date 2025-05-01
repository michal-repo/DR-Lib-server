<?php

require_once 'vendor/autoload.php';

use Dotenv\Dotenv as Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

return [
    'dbname' => $_ENV["db_name"],
    'user' => $_ENV["db_user"],
    'password' => $_ENV["db_pass"],
    'host' => $_ENV["db_host"],
    'driver' => 'pdo_mysql',
];
