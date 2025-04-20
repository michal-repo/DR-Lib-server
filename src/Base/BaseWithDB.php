<?php

namespace DRLib\Base;

use DRLib\MyDB\DB;

class BaseWithDB {

    public $db;

    public function __construct() {
        $this->db = new DB();
    }
}
