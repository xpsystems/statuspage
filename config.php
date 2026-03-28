<?php
declare(strict_types=1);

$config = [
    'site' => [
        'name'        => 'xpsystems Status',
        'domain'      => 'status.xpsystems.eu',
        'org'         => 'xpsystems',
        'org_url'     => 'https://xpsystems.eu',
        'github_url'  => 'https://github.com/xpsystems/statuspage',
        'mtex_status' => 'https://status.mtex.dev',
        'api_base'    => 'https://status.xpsystems.eu',
        'playground'  => 'https://api-sandbox.de/playground.html',
        'version'     => '3.3.0',
    ],
    'cache' => [
        'ttl'  => 90,
        'path' => __DIR__ . '/cache/status.json',
        'dir'  => __DIR__ . '/cache',
    ],
    'history' => [
        'path'        => __DIR__ . '/cache/history.json',
        'max_entries' => 1440, // ~24h at 1-min intervals; trims oldest beyond this
    ],
    // ── Database (optional — set driver to 'none' to use JSON files only) ───
    // driver: 'sqlite' | 'mysql' | 'none'
    // Before enabling sqlite or mysql, verify the extension is loaded:
    //   sqlite: php -m | grep pdo_sqlite
    //   mysql:  php -m | grep pdo_mysql
    'db' => [
        'driver' => 'none',             // ← change to 'sqlite' or 'mysql' once verified

        // SQLite — file path (relative to this file)
        'sqlite_path' => __DIR__ . '/cache/status.db',

        // MySQL — fill in when driver = 'mysql'
        'mysql_host'     => '127.0.0.1',
        'mysql_port'     => 3306,
        'mysql_dbname'   => 'xpsystems_status',
        'mysql_user'     => 'db_user',
        'mysql_password' => 'db_password',
    ],
    'ping' => [
        'timeout'   => 6,
        'useragent' => 'xpsystems-statusbot/1.0 (+https://status.xpsystems.eu)',
    ],
    // ── check.php HTTP token (leave '' to disable, set a secret for cron-via-URL)
    'check' => [
        'token' => '',   // e.g. 'my-secret-cron-token'
    ],
    'api_endpoints' => [
        [
            'method'  => 'GET',
            'path'    => '/api/status',
            'summary' => 'Overall system status summary',
        ],
        [
            'method'  => 'GET',
            'path'    => '/api/services',
            'summary' => 'All monitored services and their current status',
        ],
        [
            'method'  => 'GET',
            'path'    => '/api/service/{slug}',
            'summary' => 'Individual service status by slug',
        ],
        [
            'method'  => 'GET',
            'path'    => '/api/history',
            'summary' => 'Full check history — response times and status over time',
        ],
        [
            'method'  => 'GET',
            'path'    => '/api/history/{slug}',
            'summary' => 'Per-service check history with response times',
        ],
        [
            'method'  => 'GET',
            'path'    => '/api/ping',
            'summary' => 'Health check — returns pong',
        ],
    ],
    'services' => [
        // ── Core ────────────────────────────────────────────────────────────
        [
            'slug'        => 'xpsystems-eu',
            'name'        => 'xpsystems.eu',
            'group'       => 'Core',
            'url'         => 'https://xpsystems.eu',
            'ping_url'    => 'https://xpsystems.eu',
            'is_deployed' => true,
        ],
        [
            'slug'        => 'xpsystems-de',
            'name'        => 'xpsystems.de',
            'group'       => 'Core',
            'url'         => 'https://xpsystems.de',
            'ping_url'    => 'https://xpsystems.de',
            'is_deployed' => true,
        ],
        [
            'slug'        => 'xpsys-de',
            'name'        => 'xpsys.de',
            'group'       => 'Core',
            'url'         => 'https://xpsys.de',
            'ping_url'    => 'https://xpsys.de',
            'is_deployed' => true,
        ],

        // ── Hosting ─────────────────────────────────────────────────────────
        [
            'slug'        => 'europehost-eu',
            'name'        => 'EuropeHost.eu',
            'group'       => 'Hosting',
            'url'         => 'https://europehost.eu',
            'ping_url'    => 'https://europehost.eu',
            'is_deployed' => true,
        ],
        [
            'slug'        => 'eudomains-eu',
            'name'        => 'eudomains.eu',
            'group'       => 'Hosting',
            'url'         => 'https://eudomains.eu',
            'ping_url'    => 'https://eudomains.eu',
            'is_deployed' => false,
        ],
        [
            'slug'        => 'eushare-eu',
            'name'        => 'eushare.eu',
            'group'       => 'Hosting',
            'url'         => 'https://eushare.eu',
            'ping_url'    => 'https://eushare.eu',
            'is_deployed' => false,
        ],
        [
            'slug'        => 'swiftshare-eu',
            'name'        => 'swiftshare.eu',
            'group'       => 'Hosting',
            'url'         => 'https://swiftshare.eu',
            'ping_url'    => 'https://swiftshare.eu',
            'is_deployed' => false,
        ],
        [
            'slug'        => 'dsc-pics',
            'name'        => 'dsc.pics',
            'group'       => 'Hosting',
            'url'         => 'https://dsc.pics',
            'ping_url'    => 'https://dsc.pics',
            'is_deployed' => false,
        ],

        // ── Sovereignty ─────────────────────────────────────────────────────
        [
            'slug'        => 'eu-data-org',
            'name'        => 'eu-data.org',
            'group'       => 'Sovereignty',
            'url'         => 'https://eu-data.org',
            'ping_url'    => 'https://eu-data.org',
            'is_deployed' => true,
        ],
        [
            'slug'        => 'mail-free-eu',
            'name'        => 'mail-free.eu',
            'group'       => 'Sovereignty',
            'url'         => 'https://mail-free.eu',
            'ping_url'    => 'https://mail-free.eu',
            'is_deployed' => false,
        ],
        [
            'slug'        => 'eu-search-org',
            'name'        => 'eu-search.org',
            'group'       => 'Sovereignty',
            'url'         => 'https://eu-search.org',
            'ping_url'    => 'https://eu-search.org',
            'is_deployed' => true,
        ],

        // ── Developer ────────────────────────────────────────────────────────
        [
            'slug'        => 'api-sandbox-de',
            'name'        => 'api-sandbox.de',
            'group'       => 'Developer',
            'url'         => 'https://api-sandbox.de',
            'ping_url'    => 'https://api-sandbox.de',
            'is_deployed' => true,
        ],
        [
            'slug'        => 'status-node-1',
            'name'        => 'status.xpsystems.eu',
            'group'       => 'Developer',
            'url'         => 'https://status.xpsystems.eu',
            'ping_url'    => 'https://status.xpsystems.eu/api/ping',
            'is_deployed' => true,
        ],
        [
            'slug'        => 'status-node-2',
            'name'        => 'status.xpsys.de',
            'group'       => 'Developer',
            'url'         => 'https://status.xpsys.de',
            'ping_url'    => 'https://status.xpsys.de/api/ping',
            'is_deployed' => false,
        ],
    ],
    'groups' => ['Core', 'Hosting', 'Sovereignty', 'Developer'],
];