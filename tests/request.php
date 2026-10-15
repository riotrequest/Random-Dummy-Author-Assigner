<?php
if ('cli' !== PHP_SAPI || empty($argv[2])) { http_response_code(404); exit(1); }
require $argv[1];
if (!defined('RIOT_AUTHORS_TESTING') || !RIOT_AUTHORS_TESTING) { exit(1); }
$input = json_decode(base64_decode($argv[2]), true);
wp_set_current_user($input['user']);
$_SERVER['REQUEST_METHOD'] = $input['method'];
$_POST = $input['post'];
$_REQUEST = $_POST;
add_filter('wp_die_handler', function () { return function ($message) { throw new RuntimeException(strip_tags((string) $message)); }; });
try {
    ob_start(); riot_authors_page(); $html = ob_get_clean();
    echo wp_json_encode(array('allowed' => true, 'html' => $html));
} catch (RuntimeException $error) {
    while (ob_get_level()) { ob_end_clean(); }
    echo wp_json_encode(array('allowed' => false, 'message' => $error->getMessage()));
}
