<?php

class ErrorHandle {

    var $mail_buffer;
    var $send_emails;
    var $shutting_down;

    function __construct() {
        set_error_handler(array(&$this, 'errorHandler'));
        set_exception_handler(array(&$this, 'exceptionHandler'));

        register_shutdown_function(array(&$this, 'fatalErrorCheck'));
        register_shutdown_function(array(&$this, 'emailErrors'));

        $this->send_emails = false;
        $this->shutting_down = false;
    }

    function fatalErrorCheck() {
        $this->shutting_down = true;

        $error = error_get_last();
        if ($error['type'] == 1) {
            $this->errorHandler(E_USER_ERROR, $error['message'], $error['file'], $error['line']);
        }
    }

    function emailErrors() {
        if (strlen($this->mail_buffer) > 0) {
            $this->mail_buffer .= "Time: " . date('F j, Y, g:i a');
            $this->mailError($this->mail_buffer);
        }
    }

    /**
     * The error handling routine set by set_error_handler()
     *
     * @param string $error_type The type of error being handled.
     * @param string $error_msg The error message being handled.
     * @param string $error_file The file in which the error occurred.
     * @param integer $error_line The line in which the error occurred.
     * @param array $error_context The context in which the error occurred.
     * @return Boolean
     * @access public
     */
    function errorHandler($error_type, $error_msg, $error_file, $error_line, $error_context) {
        if (error_reporting() == 0) {
            return;
        }
        // Log to web server log
        error_log($error_msg);

        switch ($error_type) {
            case E_ERROR:
            case E_USER_ERROR:
                if ($this->send_emails) {
                    $this->mail_buffer .= 'ERROR: ' . $error_msg . ' (' . $error_file . ' on line ' . $error_line . ')' . PHP_EOL . PHP_EOL . print_r($error_context, true) . PHP_EOL . PHP_EOL . print_r($_SERVER, true) . PHP_EOL . PHP_EOL;
                    $this->mail_buffer .= print_r($_REQUEST, true) . PHP_EOL . PHP_EOL;
                }

                header("HTTP/1.1 503 Service Temporarily Unavailable");
                header("Status: 503 Service Temporarily Unavailable");
                header("Retry-After: 120");
                header("Connection: Close");

                if (!$this->shutting_down) {
                    exit(0);
                }

                break;
            case E_WARNING:
            case E_USER_WARNING:
            case E_NOTICE:
            case E_USER_NOTICE:
                if ($this->send_emails) {
                    $this->mail_buffer .= 'ERROR: ' . $error_msg . ' (' . $error_file . ' on line ' . $error_line . ')' . PHP_EOL . PHP_EOL . print_r($error_context, true) . PHP_EOL . PHP_EOL . print_r($_SERVER, true) . PHP_EOL . PHP_EOL;
                    $this->mail_buffer .= print_r($_REQUEST, true) . PHP_EOL . PHP_EOL;
                }
                break;
            case E_RECOVERABLE_ERROR:
                //$GLOBALS['LOGGER']->notice($error_msg.' (' . $error_file.' on line '.$error_line.')');
                break;
            default :
                //$GLOBALS['LOGGER']->notice('UNCAUGHT: '.$error_msg.' (' . $error_file.' on line '.$error_line.')');
                break;
        }
        return true;
    }

    function exceptionHandler($ex) {
        trigger_error($ex->getMessage(), E_USER_WARNING);
    }

    function mailError($mail_body) {
        mail('general@bookexge.com', 'Error Handler', $mail_body);
    }

    function enableEmail() {
        $this->send_emails = true;
    }

}

