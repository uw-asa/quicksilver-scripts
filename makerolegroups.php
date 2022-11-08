<?php

// run this remotely with something like:
// WPCLI="terminus remote:wp cms1-asa-uw.live --" php makerolegroups.php
if ($val = getenv('WPCLI')) {
    define('WPCLI', $val);
} else {
    define('WPCLI', 'wp');
}

define('WP_SAML_AUTH_UW_GROUP_STEM', 'uw_asa_it_web');
define('WP_SAML_AUTH_UW_GROUP_TEXTPREFIX', 'ASA Web Publishing');

function site_url() {
    global $site;

    return $site['url'];
}

function wp_roles() {
    global $site;

    $roles = json_decode(exec(WPCLI . " role list --url={$site['url']} --format=json --fields=name,role"), true);

    return new class($roles) {
        public $role_names;
        public function __construct($rolearr) {
            foreach ($rolearr as $role) {
                $this->role_names[$role['role']] = $role['name'];
            }
        }
    };
}

function get_option($option) {
    global $site;

    return json_decode(exec(WPCLI . " option get {$option} --url={$site['url']} --format=json"), true);
}

function get_bloginfo($show) {
    global $site;

    switch ( $show ) {
        case 'name':
        default:
            $output = get_option( 'blogname' );
            break;
    }
    return $output;
}

/**
 * remove prefixes and suffixes from the site's domain
 * usually just a single name, but if there are multiple,
 * reverse them and replace dots with underscores.
 * ex: emfinadmin.uw.edu => emfinadmin
 *     kb.registrar.washington.edu => registrar_kb
 */
function site_slug() {
    $domain = parse_url(site_url(), PHP_URL_HOST);

    $domain = preg_replace('/^((dev|test|live)[.-])+/', '', $domain);
    $domain = preg_replace('/\.(dev|test|live)\.cms.+$/', '', $domain);
    $domain = preg_replace('/\.cms.+$/', '', $domain);
    $domain = preg_replace('/([.-]asa)?[.-](uw|washington)\.(edu|pantheonsite\.io)$/', '', $domain);

    $parts = explode('.', $domain);
    $site = implode('_', array_reverse($parts));

    return $site;
}

/**
 * Return an associative array of groups, indexed by role
 */
function site_role_groups() {
    $site_stem = WP_SAML_AUTH_UW_GROUP_STEM.'_'.site_slug();

    $role_map = array();
    foreach (wp_roles()->role_names as $role => $name) {
        $role_map[$role] = $site_stem.'_'.str_replace('_', '-', $role);
    }
    return $role_map;
}

/**
 * Return the super admin group
 */
function super_admin_group() {
    return WP_SAML_AUTH_UW_GROUP_STEM.'_admin';
}


echo "Creating/updating UW Groups... \n";

$context_opts = array(
    'http' => array(
        'method' => 'GET',
        'header' => array(
            'Accept: application/json',
        ),
    ),
    'ssl' => array(
        'local_cert' => 'app-groupmaint.asa.uw.edu.crt',
        'local_pk' => 'app-groupmaint.asa.uw.edu.key',
    ),
);

// Get list of sites
$sites = json_decode(exec(WPCLI . " site list --format=json --fields=blog_id,url,domain"), true);

foreach($sites as &$site) {
    $blogname = htmlspecialchars_decode(get_bloginfo('name'), ENT_NOQUOTES);
    echo "\n\nUpdating role groups for {$site['url']} -- {$blogname}\n";

    $site_stem = WP_SAML_AUTH_UW_GROUP_STEM.'_'.site_slug();
    echo "UW Groups stem: {$site_stem}\n";

    $rolenames = wp_roles()->role_names;
    $groups = site_role_groups();

    $group_id = $site_stem;
    $context = stream_context_create($context_opts);
    $content = @file_get_contents("https://groups.uw.edu/group_sws/v3/group/{$group_id}", false, $context);
    $nonce = null;
    if ($content === false) {
        echo "{$group_id} does not exist, creating\n";
        $g = array("data" => array('id' => $group_id));
    } else {
        echo "{$group_id} exists, updating\n";
        $g = json_decode($content, true);
        foreach ($http_response_header as $header) {
            $parts = explode(':', $header);
            if (!isset($parts[1]))
                continue;
            if (strcasecmp(trim($parts[0]), 'ETag') == 0) {
                $nonce = trim($parts[1]);
                break;
            }
        }
    }

    /* Always reset these values */
    $g['data']['displayName'] = WP_SAML_AUTH_UW_GROUP_TEXTPREFIX . " - {$blogname}";
    $g['data']['description'] = "Group stem for roles of the site '{$blogname}'.";
    $g['data']['admins'] = array(
        array('type' => 'group', 'id' => super_admin_group()),
        array('type' => 'group', 'id' => $groups['administrator']),
    );

    $context = stream_context_create($context_opts);
    stream_context_set_option($context, 'http', 'method', 'PUT');
    $headers = array_merge($context_opts['http']['header'], array('Content-type: application/json'));
    if ($nonce) {
        $headers[] = "If-Match: {$nonce}";
    }
    stream_context_set_option($context, 'http', 'header', $headers);
    stream_context_set_option($context, 'http', 'content', json_encode($g));
    $content = file_get_contents("https://groups.uw.edu/group_sws/v3/group/{$group_id}", false, $context);

    foreach ($groups as $role => $group_id) {
        $context = stream_context_create($context_opts);
        $content = @file_get_contents("https://groups.uw.edu/group_sws/v3/group/{$group_id}", false, $context);
        $nonce = null;
        if ($content === false) {
            echo "{$group_id} does not exist, creating\n";
            $g = array("data" => array('id' => $group_id));
        } else {
            echo "{$group_id} exists, updating\n";
            $g = json_decode($content, true);
            foreach ($http_response_header as $header) {
                $parts = explode(':', $header);
                if (!isset($parts[1]))
                    continue;
                if (strcasecmp(trim($parts[0]), 'ETag') == 0) {
                    $nonce = trim($parts[1]);
                    break;
                }
            }
        }

        /* Always reset these values */
        $g['data']['id'] = $group_id;
        $g['data']['displayName'] = "{$blogname} - {$rolenames[$role]} role";
        $g['data']['description'] = "Members of this group will take the '{$rolenames[$role]}' role for the site '{$blogname}' located at {$site['url']}";
        $g['data']['admins'] = array(
            array('type' => 'group', 'id' => super_admin_group()),
            array('type' => 'group', 'id' => $groups['administrator']),
        );

        $context = stream_context_create($context_opts);
        stream_context_set_option($context, 'http', 'method', 'PUT');
        $headers = array_merge($context_opts['http']['header'], array('Content-type: application/json'));
        if ($nonce) {
            $headers[] = "If-Match: {$nonce}";
        }
        stream_context_set_option($context, 'http', 'header', $headers);
        stream_context_set_option($context, 'http', 'content', json_encode($g));
        $content = file_get_contents("https://groups.uw.edu/group_sws/v3/group/{$group_id}", false, $context);
    }
}
