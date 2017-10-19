<?php

    // do not support refresh command, this could take ages.
    if (isset($_REQUEST["refresh"]))
    {
        exit;
    }
    
    /* api/index.php */
    error_reporting(E_ERROR | E_PARSE);

    require_once "../../config.php";
    
    set_include_path(dirname(__FILE__) . PATH_SEPARATOR .
        dirname(dirname(dirname(__FILE__))) . PATH_SEPARATOR .
        dirname(dirname(dirname(__FILE__))) . "/include" . PATH_SEPARATOR .
          get_include_path());

    chdir("../..");

    define('TTRSS_SESSION_NAME', 'ttrss_api_sid');
    define('NO_SESSION_AUTOSTART', true);

    require_once "autoload.php";
    require_once "db.php";
    require_once "db-prefs.php";
    require_once "functions.php";
    require_once "sessions.php";
    
    require_once "fever_api.php";

    ini_set("session.gc_maxlifetime", 86400);

    define('AUTH_DISABLE_OTP', true);

    if (defined('ENABLE_GZIP_OUTPUT') && ENABLE_GZIP_OUTPUT &&
            function_exists("ob_gzhandler")) {

        ob_start("ob_gzhandler");
    } else {
        ob_start();
    }
        
    if ($_REQUEST["sid"]) {
        session_id($_REQUEST["sid"]);
        @session_start();
    } else if (defined('_API_DEBUG_HTTP_ENABLED')) {
        @session_start();
    }
    
    startup_gettext();

    if (!init_plugins()) return;
        
    $handler = new FeverAPI($_REQUEST);

    if ($handler->before("")) {
        if (method_exists($handler, 'index')) {
            $handler->index();
        }
        $handler->after();
    }
    
    header("Api-Content-Length: " . ob_get_length());

    ob_end_flush();
?>