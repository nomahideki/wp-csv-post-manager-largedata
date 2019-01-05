<?php

echo "launch";
//echo file_get_contents('/home/oomaekunccb/tokyobinta.com/xserver_php/php.ini');
global $argv;
if (isset($argv[1])) {
    $argfile = $argv[1];
    print $argfile;
    if (file_exists($argfile)) {
        $args_json = file_get_contents($argfile);
        rename($argfile, $argfile . '.working');

        $args = json_decode($args_json, true);
        print_r($args);
        $_POST = $args['_POST'];
        $_GET = $args['_GET'];
        $_REQUEST = $args['_REQUEST'];
        $_COOKIE = $args['_COOKIE'];
        $_SERVER = $args['_SERVER'];
        $_SESSION = $args['_SESSION'];
        $_FILES = $args['_FILES'];
        $_ENV = $args['_ENV'];
        chdir('../../../wp-admin');
        echo getcwd();
        print_r($_GET);
        require_once('options-general.php');
        rename($argfile . 'working', $argfile . '.done');
    }
}
echo "OK";
?>
