<?php

ini_set('memory_limit', '-1');

require 'includes/autoloads.php';
// Includes specific to this file
require 'includes/ParallelCurl.php';

date_default_timezone_set('GMT');

$siteReports = array();

// curl thread handler
$parallelcurl = new ParallelCurl(10, array(
    CURLOPT_FOLLOWLOCATION => 1,
    // fake the useragent, some sites block empty user agents
    CURLOPT_USERAGENT => random_user_agent(),
    // speed things up if over ssl. pretty rare, maybe this is unneeded?
    //CURLOPT_SSL_VERIFYPEER => 0,
    //CURLOPT_SSL_VERIFYHOST => 0,
    // how long to wait for connection
    CURLOPT_CONNECTTIMEOUT => 7,
    // we need to draw the line somewhere on waiting for a response
    CURLOPT_TIMEOUT => 15,
        ));

if (!$conn = connect()) {
    die('Problem with DB connect');
}

// Loop through a portion of the bookstores and check for redirects
$start_time = microtime(true);

$row = mysql_fetch_assoc(db_query('SELECT count(`Bookstore_ID`) as `total` FROM `Bookstores`;'));
$total_bookstores = $row['total'];
$day_of_week = date('w'); // int 0(Sunday) - 6
$bookstores_todo = floor($total_bookstores / 7);
$start = $bookstores_todo * $day_of_week;

$query = "SELECT `Bookstore_ID`,`Storefront_URL`,`Bookstore_Type_ID` FROM  `Bookstores` ORDER BY `Bookstore_ID` ASC LIMIT $start,$bookstores_todo;";
$result = db_query($query);
while ($row = mysql_fetch_assoc($result)) {
    //echo "Checking {$row['Bookstore_ID']}: {$row['Storefront_URL']}.\n";
    $parallelcurl->startRequest($row['Storefront_URL'], 'handle_curl_response', $row);
}
mysql_free_result($result);

// wait for all outstanding requests to finish
$parallelcurl->finishAllRequests();

$totalTime = microtime(true) - $start_time;
$statistics['bookstore'] = array(
    'total_time' => round($totalTime, 2) . 'sec',
    'avg_time_per_request' => round($totalTime / $bookstores_todo, 4) . 'sec',
    'total_checked' => $bookstores_todo,
    'query' => $query
);



// Loop through fullet campuses and update terms
$start_time = microtime(true);

$row = mysql_fetch_assoc(db_query('SELECT count(`Campus_ID`) as `total` FROM `Campuses` INNER JOIN `Bookstores` ON `Campuses`.Bookstore_ID = Bookstores.Bookstore_ID WHERE Bookstore_Type_ID = 4;'));
$total_follett_campuses = $row['total'];
$follett_todo = floor($total_follett_campuses / 7);
$start = $follett_todo * $day_of_week;

$query = "SELECT `Campus_ID` FROM `Campuses` INNER JOIN `Bookstores` ON `Campuses`.Bookstore_ID = Bookstores.Bookstore_ID WHERE Bookstore_Type_ID = 4 ORDER BY `Campus_ID` ASC LIMIT $start,$follett_todo;";
$result = db_query($query);
while ($row = mysql_fetch_assoc($result)) {
    $url = "http://text.bookexge.com/api/api.php?campus={$row['Campus_ID']}";
    //echo "Calling {$row['Campus_ID']}: {$url}.\n";
    $parallelcurl->startRequest($url, 'handle_fullett_response', $row);
}
mysql_free_result($result);

// wait for all outstanding requests to finish
$parallelcurl->finishAllRequests();

// compile our statistics
$totalTime = microtime(true) - $start_time;
$statistics['fullett'] = array(
    'total_time' => round($totalTime, 2) . 'sec',
    'avg_time_per_request' => round($totalTime / $follett_todo, 4) . 'sec',
    'total_checked' => $follett_todo,
    'query' => $query
);

// Now that we have looped through a portion of fullett schools, check if any are missing HOEA term ids
$query = <<<EOT
SELECT `Term_ID`, `Term_Name`, Campuses.Campus_ID, Campus_Name, Bookstores.Follett_HEOA_Store_Value
FROM `Terms_Cache`
INNER JOIN `Campuses` ON Campuses.Campus_ID = Terms_Cache.Campus_ID
INNER JOIN Bookstores ON Campuses.Bookstore_ID = Bookstores.Bookstore_ID
INNER JOIN Campus_Names ON Campuses.Campus_ID = Campus_Names.Campus_ID AND Is_Primary = 'Y'
WHERE `Follett_HEOA_Term_Value` IS NULL AND Bookstore_Type_ID = 4
EOT;
$result = db_query($query);
$missingTerms = array();
$missingTermIds = array();
while ($row = mysql_fetch_assoc($result)) {
    $row['heoa_url'] = "http://www.bkstr.com/webapp/wcs/stores/servlet/booklookServlet?bookstore_id-1={$row['Follett_HEOA_Store_Value']}&term_id-1=###&crn-1=111";

    $term_result = db_query("SELECT `Term_ID`, `Term_Name`, `Follett_HEOA_Term_Value` FROM Terms_Cache WHERE Campus_ID = {$row['Campus_ID']} AND Follett_HEOA_Term_Value IS NOT NULL;");
    if (mysql_num_rows($term_result) > 0) {
        $row['additional_terms'] = array();
        while ($term = mysql_fetch_assoc($term_result)) {
            $row['additional_terms'][] = $term;
        }
    }

    $missingTerms[] = $row;
    $missingTermIds[] = $row['Term_ID'];
}

// Print the results
print_r($statistics);
echo "\n******************************\n Bookstore Redirects:";
print_r($siteReports);
echo "\n******************************\n Fullett Missing Terms:";
print_r($missingTerms);
echo implode(',', $missingTermIds);

/* * **********************************
 * Helper Functions
 * ********************************** */

function db_query($query) {
    $result = mysql_query($query);
    if (!$result) {
        $message = 'Invalid query: ' . mysql_error() . "\n";
        $message .= 'Whole query: ' . $query;
        die($message);
    }
    return $result;
}

function handle_curl_response($content, $url, $ch, $report) {
    global $siteReports;

    if (isset($report['error_code'])) {
        $report['error'] = curl_error($ch);
    } else {
        $report['destination_url'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        $html = str_get_html($content);

        if ($html) {
            foreach ($html->find('meta') as $meta) {
                if (strcasecmp($meta->getAttribute('http-equiv'), 'refresh') === 0) {
                    $report['meta'] = $meta->content;
                    break;
                }
            }
            $html->clear();
            unset($html);
        } else {
            $report['error'] = 'unable to parse html';
        }
    }

    if (isset($report['error']) || $report['destination_url'] != $report['Storefront_URL'] || isset($report['meta'])) {
        $query = 'SELECT `Campuses`.`Campus_ID`,`Campus_Names`.`Campus_Name` FROM `Campuses` LEFT JOIN `Campus_Names` ON `Campuses`.`Campus_ID` = `Campus_Names`.`Campus_ID` AND .`Campus_Names`.`Is_Primary` = \'Y\' WHERE `Bookstore_ID` = ' . $report['Bookstore_ID'];
        $result = mysql_query($query);
        if (!$result) {
            $message = 'Invalid query: ' . mysql_error() . "\n";
            $message .= 'Whole query: ' . $query;
            die($message);
        }
        $row = mysql_fetch_assoc($result);
        $report['Campus_ID'] = $row['Campus_ID'];
        $report['Campus_Name'] = $row['Campus_Name'];
        $siteReports[] = $report;
    }

    curl_close($ch);
}

function handle_fullett_response($content, $url, $ch, $report) {
    // Do I care about anything here?
    curl_close($ch);
}
