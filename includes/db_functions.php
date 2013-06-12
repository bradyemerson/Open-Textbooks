<?php

$database_connection = null;

function connect() {
    global $database_connection;

    if (!$database_connection) {
        if (!($database_connection = mysql_connect(DB_HOST, DB_USERNAME, DB_PASSWORD)) || !mysql_select_db(DB_NAME)) {
            return false;
        }
    }
    return $database_connection;
}
