<?php

// Accesses storefront urls to determine if they are redirecting

require 'includes/autoloads.php';
// Includes specific to this file
require 'includes/ParallelCurl.php';

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
        //CURLOPT_CONNECTTIMEOUT => 7,
        // we need to draw the line somewhere on waiting for a response
        //CURLOPT_TIMEOUT => 15,
        ));

if (!$conn = connect()) {
    die('Problem with DB connect');
}

$query = 'SELECT `Bookstore_ID`,`Storefront_URL`,`Bookstore_Type_ID` FROM  `Bookstores` WHERE Bookstore_ID > 1180 LIMIT 200';
$result = mysql_query($query);
if (!$result) {
    $message = 'Invalid query: ' . mysql_error() . "\n";
    $message .= 'Whole query: ' . $query;
    die($message);
}

while ($row = mysql_fetch_assoc($result)) {
    echo "Checking {$row['Storefront_URL']}.\n";
    $parallelcurl->startRequest($row['Storefront_URL'], 'handle_curl_response', $row);
}

mysql_free_result($result);

// wait for all outstanding requests to finish
$parallelcurl->finishAllRequests();

// compile our statistics
/* $totalTime = microtime(true) - $STARTTIME;
  $totalChecked = count($siteReports);
  $statistics = array(
  'total_time' => round($totalTime, 2) . 'sec',
  'avg_time_per_link' => round($totalTime / ($totalChecked), 4) . 'sec',
  'avg_time_per_request' => round($totalTime / $totalChecked, 4) . 'sec',
  'checked' => $totalChecked
  );
  print_r($statistics); */
print_r($siteReports);

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

