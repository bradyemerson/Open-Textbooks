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


echo 'Starting checks: ';
echo_memory_usage();
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

echo 'Done with checks: ';
echo_memory_usage();

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
SELECT `Term_ID`, `Term_Name`, Campuses.Campus_ID, Campus_Name, Bookstores.Follett_HEOA_Store_Value, Bookstores.Bookstore_ID,
    Follett_Terms_Pattern.Fall_Pattern, Follett_Terms_Pattern.Spring_Pattern, 
    Follett_Terms_Pattern.Winter_Pattern, Follett_Terms_Pattern.Summer_Pattern
FROM `Terms_Cache`
INNER JOIN `Campuses` ON Campuses.Campus_ID = Terms_Cache.Campus_ID
INNER JOIN Bookstores ON Campuses.Bookstore_ID = Bookstores.Bookstore_ID
INNER JOIN Campus_Names ON Campuses.Campus_ID = Campus_Names.Campus_ID AND Is_Primary = 'Y'
LEFT JOIN Follett_Terms_Pattern ON Follett_Terms_Pattern.Bookstore_ID = Bookstores.Bookstore_ID
WHERE `Follett_HEOA_Term_Value` IS NULL AND Bookstore_Type_ID = 4
EOT;
$result = db_query($query);
$missingTerms = array();
$missingTermIds = array();
while ($row = mysql_fetch_assoc($result)) {
    $row['heoa_url'] = "http://www.bkstr.com/webapp/wcs/stores/servlet/booklookServlet?bookstore_id-1={$row['Follett_HEOA_Store_Value']}&term_id-1=###&crn-1=111";
    
    // Search for the year
    if (preg_match('([\d]{2,4})', $row['Term_Name'], $preg_matches) === 1 && count($preg_matches) === 1) {
        $term_year = $preg_matches[0];
        if (strlen($term_year) === 2) {
            $term_year = "20" . $term_year;
        }
        $school_year = $term_year;
        
        /*****
         * S = School Year 4 - Add a year
         * s = School Year 2 - Add a year
         * T = School Year 4 - Subtract a year
         * t = School Year 2 - Subtract a year
         * Y = Year 4 - 2014
         * y = Year 2 - 14
         * U = urlencode - Fall+2014
         */
        
        $pattern = null;
        if (stripos($row['Term_Name'], 'Fall') !== false) {
            if ($row['Fall_Pattern']) {
                $pattern = $row['Fall_Pattern'];
            }
        } else if (stripos($row['Term_Name'], 'Spring') !== false) {
            if ($row['Spring_Pattern']) {
                $pattern = $row['Spring_Pattern'];
            }
        } else if (stripos($row['Term_Name'], 'Summer') !== false) {
            if ($row['Summer_Pattern']) {
                $pattern = $row['Summer_Pattern'];
            }
        } else if (stripos($row['Term_Name'], 'Winter') !== false) {
            if ($row['Winter_Pattern']) {
                $pattern = $row['Winter_Pattern'];
            }
        }
        
        if ($pattern) {
            $term_year = intval($term_year);
            $row['term_year'] = $term_year;
            $pattern = str_replace('Y', $term_year, $pattern);
            $pattern = str_replace('y', substr($term_year, 2), $pattern);
            $pattern = str_replace('S', $term_year + 1, $pattern);
            $pattern = str_replace('s', substr($term_year + 1, 2), $pattern);
            $pattern = str_replace('T', $term_year - 1, $pattern);
            $pattern = str_replace('t', substr($term_year - 1, 2), $pattern);
            $pattern = str_replace('U', urlencode($row['Term_Name']), $pattern);
            
            $row['test_url'] = str_replace('###', $pattern, $row['heoa_url']);
            
            // test the url
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $row['test_url'],
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_FOLLOWLOCATION => 1,
                CURLOPT_USERAGENT => random_user_agent(),
                CURLOPT_CONNECTTIMEOUT => 7,
                CURLOPT_TIMEOUT => 15,
            ));
            
            $test_response = curl_exec($ch);
            if (!trim($test_response) || curl_error($ch)) {
                $row['error'] = curl_error($ch);
            } else if (strpos($test_response, 'We are unable to find the requested term') !== false) {
                $row['error'] = 'Term not found';
            } else if (stripos($test_response, $row['Term_Name']) !== false) {
                db_query("UPDATE `Terms_Cache` SET `Follett_HEOA_Term_Value` = \"{$pattern}\" WHERE `Term_ID` = {$row['Term_ID']};");
                continue;
            }
        }
    }
    

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

        $html = str_get_dom($content);

        if ($html) {
            foreach ($html('meta') as $meta) {
                if (strcasecmp($meta->{'http-equiv'}, 'refresh') === 0) {
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

function echo_memory_usage() {
    $mem_usage = memory_get_usage(true);

    if ($mem_usage < 1024) {
        echo $mem_usage . " bytes";
    } elseif ($mem_usage < 1048576) {
        echo round($mem_usage / 1024, 2) . " kilobytes";
    } else {
        echo round($mem_usage / 1048576, 2) . " megabytes";
    }

    echo "\n";
}
