<?php

require('includes/autoloads.php');

date_default_timezone_set('GMT');

$first_start = microtime(true);

$user_token = time() . rand(); //globalled in url_functions

$json = array();
$json['data'] = array();

if (!$conn = connect()) {
    $json['status'] = 'Error: DB Connect failure';
} else if (!valid_ISBN13($_GET['isbn'])) {
    $json['status'] = 'Error: Invalid ISBN';
} else if (!valid_ID($_GET['campus'])) {
    $json['status'] = 'Error: Invalid campus id';
} else {
    $query = 'SELECT
        Campuses.Campus_ID, Campuses.Location,
        Campus_Names.Campus_Name, Campuses.Program_Value, Campuses.Campus_Value,
        Bookstores.Bookstore_ID, Bookstores.Storefront_URL, Bookstores.Fetch_URL, Bookstores.Store_Value, Bookstores.Follett_HEOA_Store_Value, Bookstores.Multiple_Campuses,
        Bookstore_Types.Bookstore_Type_Name
        FROM Campuses
        INNER JOIN (Campus_Names) ON (Campuses.Campus_ID = Campus_Names.Campus_ID AND Campus_Names.Is_Primary = "Y")
        INNER JOIN (Bookstores, Bookstore_Types) ON (Bookstores.Bookstore_ID = Campuses.Bookstore_ID AND Bookstores.Bookstore_Type_ID = Bookstore_Types.Bookstore_Type_ID)
        WHERE Campuses.Enabled = "Y" AND Campuses.Campus_ID = ' . mysql_real_escape_string($_GET['campus']);

    if (!$result = mysql_query($query)) {
        $json['status'] = 'Error: SQL query based on campus=' . $_GET['campus'] . ' yielded error: ' . mysql_error();
    } else if (mysql_num_rows($result) == 0) {
        $json['status'] = 'Error: SQL query based on campus=' . $_GET['campus'] . ' yielded no results';
    } else {
        $row = mysql_fetch_assoc($result);
        $row['isbn'] = $_GET['isbn'];

        $data = update_buyback_items_from_bookstore($row);

        $select = 'SELECT ISBN as isbn, Title as title, Edition as edition,
        Authors as authors, Year as year, Publisher as publisher
        FROM Items WHERE ISBN = \'' . mysql_real_escape_string($row['isbn']) . '\'';
        if (!$result = mysql_query($select)) {
            // not going to fail on this
        } else if (mysql_num_rows($result) != 0) {
            $row = mysql_fetch_assoc($result);
            $data = array_merge($data, $row);
        }

        $json['data'] = $data;
        $json['status'] = 'ok';
    }
}

$json['total_time'] = round(microtime(true) - $first_start, 3) * 1000;

header("Content-type: application/json");
echo json_encode($json);