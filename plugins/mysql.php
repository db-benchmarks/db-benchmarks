<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class mysql extends engine {

    protected $port = 3306;
    protected $mysql = null; // mysql connection
    protected $user = "root";
    protected $db = "default";

    public function __construct($type)
    {
        parent::__construct($type);
        mysqli_report(MYSQLI_REPORT_OFF);
    }

    protected function url() {
        return "https://mysql.com/";
    }

    protected function description() {
        return "MySQL is the world's most popular open source database";
    }
    
    // attempts to fetch info about engine and return it
    public function getInfo() {
        $m = new mysqli();
        @$m->real_connect('127.0.0.1', $this->user, '', $this->db, $this->port);
        if ($m->connect_error) return false;
        $res = $m->query("show variables");
        if (!$res) return false;
        $out = [];
        while ($row = $res->fetch_row()) $out[$row[0]] = $row[1];
        $ret['variables'] = $out;
        $ret['version'] = $out['version'];
        return $ret;
    }

    protected function appendType($info) {
        return "";
    }

    protected function prepareQuery($query) {
        return $query; // no modifications required
    }

    // returns true when it's possible to connect to engine and it can run a simple query
    protected function canConnect() {
        $m = new mysqli();
        @$m->real_connect('127.0.0.1', $this->user, '', $this->db, $this->port);
        if (!@$m->connect_error) {
            $res = @$m->query("show status");
            if ($res) return true;
        }
        return false;
    }

    protected function beforeQuery() {
        $this->mysql = new mysqli();
        $this->mysql->options(MYSQL_OPT_READ_TIMEOUT, self::$commandLineArguments['query_timeout']);
        ini_set('mysqlnd.net_read_timeout', self::$commandLineArguments['query_timeout']);
        @$this->mysql->real_connect('127.0.0.1', $this->user, '', $this->db, $this->port);
    }

    // runs one query against engine
    // must respect self::$commandLineArguments['query_timeout']
    // must return ['timeout' => true] in case of timeout
    protected function testOnce($query): array
    {
        try {
            $res = $this->mysql->query($query);
            $errorResult = $this->parseMysqlError($this->mysql->errno,
                $this->mysql->error, 2006);
            if ($errorResult) {
                return $errorResult;
            }
        } catch (Exception $exception) {
            return ['error' => true, 'message' => $exception->getMessage()];
        }

        return ['error' => false, 'response' => $res];
    }

    // To collect query stats after the query
    protected function afterQuery() {
        return '';
    }

    // parses query result and returns it in the format that should be common across all engines
    protected function parseResult($result) {
        $res = [];
        if ($result and $result->num_rows > 0) {
            while ($hit = $result->fetch_assoc()) {
                $ar = [];
                foreach ($hit as $k=>$v) {
                    if ($k == 'id') continue; // removing id from the output sice Elasticsearch can't return it https://github.com/elastic/elasticsearch/issues/30266
                    if (is_float($v)) $v = round($v, 4); // this is a workaround against different floating point calculations in different engines
                    $ar[$k] = $v;
                }
                ksort($ar);
                $res[] = $ar;
            }
        }
        return $res;
    }

    // sends a command to engine to drop its caches
    protected function dropEngineCache() {
        $m = new mysqli();
        @$m->real_connect('127.0.0.1', $this->user, '', $this->db, $this->port);
        if ($m->connect_error) return false;
        $m->query("RESET QUERY CACHE");
    }
}
