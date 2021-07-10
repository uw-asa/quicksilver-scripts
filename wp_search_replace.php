<?php
echo "Replacing previous environment urls with new environment urls... \n";

if (getenv('DRY_RUN')) {
    define('DRY_RUN', ' --dry-run');
} else {
    define('DRY_RUN', '');
}

// run this remotely with something like:
// WPCLI="terminus remote:wp cms1-asa-uw.dev --" PANTHEON_SITE_NAME=cms1-asa-uw PANTHEON_ENVIRONMENT=dev php code/private/scripts/wp_search_replace.php
if ($val = getenv('WPCLI')) {
    define('WPCLI', $val);
} else {
    define('WPCLI', 'wp');
}

if ( $env = getenv('PANTHEON_ENVIRONMENT') ) {
    $asa_domain = preg_replace('/(cms\d+)-asa-uw/', '$1.asa.uw.edu', getenv('PANTHEON_SITE_NAME'));
    echo "Site is {$asa_domain}\n";

    switch( $env ) {
    default:
        echo "Unknown environment. Not doing anything\n";
        break;

    case 'live':
        echo "This is live environment. Not doing anything\n";
        break;

    case 'test':
    case 'dev':

        // Wordpress needs to know what the db thinks the main site is in order to bootstrap
        $main_site_from = exec(WPCLI . " db query 'SELECT domain FROM wp_site' --skip-column_names");

        // Gets the main site according to wp-config.php
        $main_site_to = exec(WPCLI . " config get DOMAIN_CURRENT_SITE");

        // Handle the main site and any subdir sites
        echo "Network replacing {$main_site_from} with {$main_site_to}\n";
        passthru(WPCLI . " search-replace '{$main_site_from}' '{$main_site_to}' --network --url='{$main_site_from}' --report-changed-only" . DRY_RUN);

        // Get list of sites
        $sites = json_decode(exec(WPCLI . " site list --url={$main_site_to} --format=json --fields=blog_id,url,domain"), true);

        foreach($sites as &$site) {
            $matches = array();

            // Network's main site - or maybe a subdir site - handled above
            if ($site['domain'] == $main_site_to) {
                continue;
            }

            $parts = explode('.', $site['domain']);
            $sitename = array_shift($parts);
            while (preg_match('/^(dev|test)$/', $sitename)) {
                $sitename = array_shift($parts);
            }

            if (preg_match('/^\w+-(\w+)-asa-uw$/', $sitename, $matches)) {
                $sitename = $matches[1];
            }

            $site['newdomain'] = "{$sitename}.{$env}.{$asa_domain}";

            if ($site['newdomain'] == $site['domain']) {
                echo "Not replacing {$site['domain']}, already done\n";
                continue;
            }

            echo "Replacing {$site['domain']} with {$site['newdomain']}\n";
            passthru(WPCLI . " search-replace '{$site['domain']}' '{$site['newdomain']}' --url='{$site['domain']}' --report-changed-only" . DRY_RUN);
        }
        break;
    }
}

echo "Done replacing URLs.\n";

?>