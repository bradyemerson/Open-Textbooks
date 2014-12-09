<?php

//error_reporting(0); //report all errors during development..

$root = dirname(__FILE__);

//config..
require($root . '/db_config.php');
define('CACHE_DIR', $root . '/../tmp/');
//require($root . '/proxy_config.php');
//includes..
require($root . '/book_functions.php');
require($root . '/bookstore_functions.php');
require($root . '/db_functions.php');
require($root . '/math_functions.php');
require($root . '/parsing_functions.php');
require($root . '/url_functions.php');
require($root . '/validation_functions.php');
//require($root . '/simple_html_dom.php');
require($root . '/ganon.php');

require($root . '/error_handler.php');
$errorHandler = new ErrorHandle();
if (strcmp($_SERVER['SERVER_NAME'], 'localhost') !== 0 && strpos($_SERVER['SERVER_NAME'], 'dev') !== 0) {
    $errorHandler->enableEmail();
}