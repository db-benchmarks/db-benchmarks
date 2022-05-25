<?php

/* Copyright (C) 2022 Manticore Software Ltd
 * You may use, distribute and modify this code under the
 * terms of the AGPLv3 license.
 *
 * You can find a copy of the AGPLv3 license here
 * https://www.gnu.org/licenses/agpl-3.0.txt
 */

class mysql_percona extends mysql {

    protected function url() {
        return "https://www.percona.com/software/mysql-database/percona-server";
    }

    protected function description() {
        return "Percona Server for MySQL® is a free, fully compatible, enhanced and open source drop-in replacement for any MySQL database";
    }
}
