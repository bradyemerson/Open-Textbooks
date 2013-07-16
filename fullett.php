<?php

/**
 * @project Book Exchange
 * @author Brady Emerson ()
 * @copyright 2012
 * @created 2:52 11/15/2012
 * @updated <!-- phpDesigner :: Timestamp --><!-- /Timestamp -->
 */
for ($i = 0; $i < 10000; $i++) {
    $options = array(
        CURLOPT_FOLLOWLOCATION => true,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => 'http://www.bkstr.com/webapp/wcs/stores/servlet/booklookServlet?bookstore_id-1=095&term_id-1=' . $i,
            //CURLOPT_URL => 'http://www.bkstr.com/webapp/wcs/stores/servlet/booklookServlet?bookstore_id-1=812&term_id-1='.$i,
    );

    $ch = curl_init();
    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);

    curl_close($ch);

    if (stripos($response, 'Unable to find the requested term') === false) {
        echo "Found term $i.\n";
        exit();
    }

    usleep(2 * 1000000);
}