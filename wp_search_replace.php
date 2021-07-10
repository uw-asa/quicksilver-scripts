<?php
echo "Replacing previous environment urls with new environment urls... \n";

define('DRY_RUN', '');
// define('DRY_RUN', ' --dry-run');

// run this remotely with something like:
// WPCLI="terminus remote:wp cms1-asa-uw.dev --" PANTHEON_SITE_NAME=cms1-asa-uw PANTHEON_ENVIRONMENT=dev php code/private/scripts/wp_search_replace.php
if (array_key_exists('WPCLI', $_ENV)) { 
    define('WPCLI', $_ENV['WPCLI']);
} else {
    define('WPCLI', 'wp');
}

if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
    $asa_domain = preg_replace('/(cms\d+)-asa-uw/', '$1.asa.uw.edu', $_ENV['PANTHEON_SITE_NAME']);

    switch( $_ENV['PANTHEON_ENVIRONMENT'] ) {
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

            // dev or test site, <site>.[dev|test].cms[1|2].asa.uw.edu
            // or pending live site, <site>.cms[1|2].asa.uw.edu
            } elseif (preg_match('/^([^\.\s]+)\.(?:dev\.|test\.|)cms\d+\.asa\.uw\.edu$/', $site['domain'], $matches)) {
                $site['newdomain'] = "{$matches[1]}.{$_ENV['PANTHEON_ENVIRONMENT']}.{$asa_domain}";

            // live site, <site>.uw.edu or <site>.washington.edu
            } elseif (preg_match('/^(?:dev\.)?([^\.\s]+)\.(?:uw|washington)\.edu$/', $site['domain'], $matches)) {
                $site['newdomain'] = "{$matches[1]}.{$_ENV['PANTHEON_ENVIRONMENT']}.{$asa_domain}";

            } else {
                echo "Don't know how to translate site {$site['domain']}, skipping\n";
                continue;
            }

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