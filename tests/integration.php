<?php
/** php tests/integration.php /path/to/disposable-wordpress/wp-load.php */
if ('cli' !== PHP_SAPI || empty($argv[1])) { http_response_code(404); exit(1); }
require $argv[1];
if (!defined('RIOT_AUTHORS_TESTING') || !RIOT_AUTHORS_TESTING || is_multisite()) { throw new RuntimeException('Use a disposable single-site installation with RIOT_AUTHORS_TESTING enabled.'); }
require_once dirname(__DIR__) . '/dummy-author-replacer.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
riot_authors_install();
$admins = get_users(array('role' => 'administrator', 'number' => 1));
if (!$admins) { throw new RuntimeException('An administrator is required.'); }
$admin = $admins[0]->ID;
wp_set_current_user($admin);
$old_enabled = get_option('riot_authors_enabled', null);
$baseline = get_option('riot_authors_start_id');
$prior_pool = array();
foreach (riot_authors_roster() as $user) { $prior_pool[$user->ID] = get_user_meta($user->ID, '_riot_authors_retired', true); update_user_meta($user->ID, '_riot_authors_retired', '1'); }
$users = array(); $posts = array(); $checks = 0;
$prefix = 'riot-test-' . bin2hex(random_bytes(5));
function ra_check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } $checks++; echo "PASS: $message\n"; }
function ra_manage($action, $data = array()) { return riot_authors_manage(array_merge(array('riot_authors_action' => $action), $data)); }
function ra_post($status = 'draft', $type = 'post') {
    global $posts, $admin, $prefix;
    $id = wp_insert_post(array('post_title' => $prefix, 'post_content' => "A quoted \'fixture\' with a backslash \\ and no side effects.", 'post_type' => $type, 'post_status' => $status, 'post_author' => $admin), true);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $posts[] = $id; return $id;
}
function ra_request($post, $user = null, $method = 'POST') {
    global $admin, $argv;
    $input = array('user' => null === $user ? $admin : $user, 'method' => $method, 'post' => $post);
    $proc = proc_open(array(PHP_BINARY, __DIR__ . '/request.php', realpath($argv[1]), base64_encode(wp_json_encode($input))), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($proc);
    $result = json_decode($out, true);
    if ($code || !$result) { throw new RuntimeException('Request failed: ' . $err . substr($out, 0, 200)); }
    return $result;
}
try {
    update_option('riot_authors_enabled', '1', false);
    ra_check(array() === get_role('dummy_author')->capabilities, 'The dummy role has no capabilities.');
    ra_check(!is_wp_error(ra_manage('create', array('display_name' => $prefix . ' Morgan', 'description' => '<b>Fictional</b> byline.'))), 'An administrator can create a profile.');
    $pool = riot_authors_pool(); $one = $pool[0]->ID; $users[] = $one;
    ra_check(1 === count($pool) && $prefix . ' Morgan' === $pool[0]->display_name, 'The public display name is preserved independently of the random login.');
    ra_check('Fictional byline.' === $pool[0]->description, 'Profile bios are plain text.');
    ra_check('' === $pool[0]->user_email && 0 === strpos($pool[0]->user_login, 'riot_byline_'), 'Creating a byline requires no email or chosen credentials.');
    ra_check(!is_wp_error(ra_manage('create', array('display_name' => $prefix . ' Avery'))), 'A second profile can join the pool.');
    foreach (riot_authors_pool() as $user) { if ($user->ID !== $one) { $two = $user->ID; $users[] = $two; } }
    ra_check(is_wp_error(ra_manage('create', array('display_name' => $prefix . ' Morgan'))), 'Duplicate roster names are refused.');
    ra_check(is_wp_error(ra_manage('create', array('display_name' => array('bad')))), 'Malformed names are refused.');
    ra_check(is_wp_error(ra_manage('create', array('display_name' => str_repeat('x', 101)))), 'Overlong names are refused.');
    ra_check(is_wp_error(ra_manage('retire', array('author_id' => $admin))), 'An administrator cannot be targeted as a dummy profile.');
    $mixed = get_userdata($two); $mixed->add_role('author');
    ra_check(is_wp_error(ra_manage('retire', array('author_id' => $two))) && 1 === count(riot_authors_pool()), 'Mixed-role accounts are excluded and cannot be modified here.');
    $mixed->remove_role('author');
    $privileged = get_userdata($two); $privileged->add_cap('edit_posts');
    ra_check(!riot_authors_eligible(get_userdata($two)), 'Individually privileged accounts are excluded from assignment.');
    $privileged->remove_cap('edit_posts');
    wp_set_current_user(0);
    ra_check(is_wp_error(ra_manage('settings', array('enabled' => '0'))), 'Unauthenticated mutations fail closed.');
    $subscriber = wp_insert_user(array('user_login' => $prefix . '-subscriber', 'user_pass' => wp_generate_password(32), 'role' => 'subscriber')); $users[] = $subscriber;
    wp_set_current_user($subscriber);
    ra_check(is_wp_error(ra_manage('create', array('display_name' => 'Denied'))), 'A subscriber cannot create profiles.');
    wp_set_current_user($admin);
    $password = wp_generate_password(40); wp_set_password($password, $one); $dummy = get_userdata($one);
    ra_check(is_wp_error(wp_authenticate($dummy->user_login, $password)), 'Dummy profiles cannot authenticate with a valid password.');
    ra_check(false === apply_filters('allow_password_reset', true, $one), 'Dummy password recovery is blocked.');
    ra_check(false === apply_filters('wp_is_application_passwords_available_for_user', true, $dummy), 'Dummy application passwords are disabled.');
    $expiry = time() + HOUR_IN_SECONDS;
    $session = WP_Session_Tokens::get_instance($one)->create($expiry);
    $_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie($one, $expiry, 'auth', $session);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($one, $expiry, 'logged_in', $session);
    ra_check(0 === (int) apply_filters('determine_current_user', false), 'A valid dummy session cookie cannot become the current user.');
    WP_Session_Tokens::get_instance($one)->destroy($session);
    $session = WP_Session_Tokens::get_instance($admin)->create($expiry);
    $_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie($admin, $expiry, 'auth', $session);
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($admin, $expiry, 'logged_in', $session);
    ra_check($admin === (int) apply_filters('determine_current_user', false), 'A valid administrator session cookie still authenticates.');
    WP_Session_Tokens::get_instance($admin)->destroy($session);
    unset($_COOKIE[AUTH_COOKIE], $_COOKIE[LOGGED_IN_COOKIE]);
    ra_check(2 === count(riot_authors_pool()), 'Both eligible profiles return to the pool.');
    $draft = ra_post(); $content = get_post_field('post_content', $draft);
    ra_check($admin === (int) get_post_field('post_author', $draft), 'Draft creation does not assign a dummy author.');
    wp_update_post(array('ID' => $draft, 'post_status' => 'publish'));
    $assigned = (int) get_post_field('post_author', $draft);
    ra_check(in_array($assigned, array($one, $two), true), 'Draft-to-publish chooses an active profile.');
    ra_check($content === get_post_field('post_content', $draft), 'Assignment preserves quoted post content.');
    $record = get_post_meta($draft, '_riot_authors_assignment', true);
    ra_check($record['from'] === $admin && $record['to'] === $assigned, 'Private metadata records the original and selected author.');
    wp_update_post(array('ID' => $draft, 'post_title' => $prefix . ' edited'));
    wp_update_post(array('ID' => $draft, 'post_status' => 'draft'));
    wp_update_post(array('ID' => $draft, 'post_status' => 'publish'));
    ra_check($assigned === (int) get_post_field('post_author', $draft) && $record === get_post_meta($draft, '_riot_authors_assignment', true), 'Edits and republication do not reroll an assigned post.');
    $direct = ra_post('publish');
    ra_check(in_array((int) get_post_field('post_author', $direct), array($one, $two), true), 'Direct publication also assigns a profile.');
    $page = ra_post('publish', 'page');
    ra_check($admin === (int) get_post_field('post_author', $page), 'Pages are not reassigned.');
    $future = ra_post('draft');
    wp_update_post(array('ID' => $future, 'post_status' => 'future', 'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS), 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS), 'edit_date' => true));
    ra_check($admin === (int) get_post_field('post_author', $future), 'Scheduled drafts retain their author before publication.');
    wp_publish_post($future);
    ra_check(in_array((int) get_post_field('post_author', $future), array($one, $two), true), 'Scheduled publication uses the same pool.');
    ra_manage('settings', array('enabled' => '0')); $paused = ra_post('publish');
    ra_check($admin === (int) get_post_field('post_author', $paused), 'Paused assignment preserves the chosen author.');
    ra_manage('settings', array('enabled' => '1'));
    wp_update_post(array('ID' => $paused, 'post_status' => 'draft')); wp_update_post(array('ID' => $paused, 'post_status' => 'publish'));
    ra_check($admin === (int) get_post_field('post_author', $paused), 'A publication observed while paused is not reassigned later.');
    ra_manage('retire', array('author_id' => $one)); ra_manage('retire', array('author_id' => $two));
    ra_check('publish' === get_post_status($draft) && $assigned === (int) get_post_field('post_author', $draft) && get_userdata($one), 'Retirement preserves accounts, existing bylines, and published status.');
    $empty = ra_post('publish');
    ra_check($admin === (int) get_post_field('post_author', $empty), 'An empty pool leaves a new post alone.');
    ra_manage('activate', array('author_id' => $one));
    ra_check(array($one) === wp_list_pluck(riot_authors_pool(), 'ID'), 'Reactivation returns only the selected profile to the pool.');
    $single = ra_post('publish');
    ra_check($one === (int) get_post_field('post_author', $single), 'A single-member pool assigns predictably.');
    $before_slug = get_userdata($one)->user_nicename;
    ra_manage('edit', array('author_id' => $one, 'display_name' => $prefix . ' Renamed', 'description' => 'Updated bio.'));
    ra_check($prefix . ' Renamed' === get_userdata($one)->display_name && $before_slug === get_userdata($one)->user_nicename, 'Profile editing updates the display name while preserving its archive slug.');
    $historical = ra_post(); update_option('riot_authors_start_id', $historical, false); wp_update_post(array('ID' => $historical, 'post_status' => 'publish'));
    ra_check($admin === (int) get_post_field('post_author', $historical), 'Pre-upgrade posts and drafts are protected by the saved starting ID.');
    update_option('riot_authors_start_id', $baseline, false);
    $request = new WP_REST_Request('POST', '/wp/v2/posts');
    $request->set_body_params(array('title' => $prefix . ' REST', 'status' => 'publish', 'content' => 'REST fixture.'));
    $response = rest_do_request($request); $data = $response->get_data();
    if (isset($data['id'])) { $posts[] = $data['id']; }
    ra_check(201 === $response->get_status() && $one === (int) get_post_field('post_author', $data['id']), 'REST / block-editor publication assigns a profile.');
    ra_check($one === $data['author'], 'The REST response immediately contains the assigned author.');
    $form = array('riot_authors_action' => 'settings', 'enabled' => '0', '_wpnonce' => 'invalid');
    ra_check(!ra_request($form)['allowed'], 'An invalid form nonce prevents mutation.');
    $form['_wpnonce'] = wp_create_nonce('riot_authors_manage');
    ra_check(!ra_request($form, $subscriber)['allowed'], 'The admin page rejects a subscriber before rendering or mutation.');
    $get = ra_request($form, null, 'GET');
    ra_check($get['allowed'] && false === strpos($get['html'], 'Assignment paused. Existing'), 'GET requests cannot submit assignment settings.');
    $valid = ra_request($form);
    ra_check($valid['allowed'] && false !== strpos($valid['html'], 'Assignment paused. Existing'), 'A valid administrator form nonce saves settings.');
    ra_check(false === has_action('wp_ajax_delete_author') && false === has_action('wp_ajax_add_new_author'), 'Unsafe legacy AJAX endpoints are removed.');
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array(); ob_start(); riot_authors_page(); $html = ob_get_clean();
    $dom = new DOMDocument(); @$dom->loadHTML($html);
    ra_check(1 === (new DOMXPath($dom))->query('//*[@id="riot-authors-app"]')->length && false !== strpos($html, 'Reactivate'), 'The dashboard shows active and retired profiles.');
    riot_authors_assets('dashboard'); ra_check(!wp_script_is('riot-authors', 'enqueued'), 'Unrelated admin pages do not load plugin assets.');
    riot_authors_assets('toplevel_page_riot-authors'); ra_check(wp_script_is('riot-authors', 'enqueued') && wp_style_is('riot-authors', 'enqueued'), 'The console loads local versioned assets without jQuery.');
    echo "\n$checks checks passed on WordPress " . get_bloginfo('version') . ' / PHP ' . PHP_VERSION . ".\n";
} finally {
    remove_action('wp_after_insert_post', 'riot_authors_assign', 20);
    foreach ($posts as $id) { wp_delete_post($id, true); }
    foreach (array_unique($users) as $id) { wp_delete_user($id, $admin); }
    foreach ($prior_pool as $id => $value) { update_user_meta($id, '_riot_authors_retired', $value); }
    update_option('riot_authors_start_id', $baseline, false);
    if (null === $old_enabled) { delete_option('riot_authors_enabled'); } else { update_option('riot_authors_enabled', $old_enabled, false); }
}
