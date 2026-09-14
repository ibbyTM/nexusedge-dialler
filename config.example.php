<?php
/**
 * Nexus Edge CRM configuration.
 *
 * Copy this file to config.php and fill in your database details.
 * config.php is ignored by git and is denied to the browser by .htaccess.
 */
return [
    // Database (create these in cPanel > MySQL Databases)
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'cpaneluser_crm',
        'user'    => 'cpaneluser_crmuser',
        'pass'    => 'change-me',
        'charset' => 'utf8mb4',
    ],

    // Shown in the page title and header. Keep it as is.
    'app_name' => 'Nexus Edge CRM',

    // All dates and times are stored and displayed in this timezone.
    'timezone' => 'Europe/London',

    // Name of the session cookie.
    'session_name' => 'nexusedge_sess',

    // Companies whose name contains any of these strings (case-insensitive)
    // are excluded during CSV import and counted as "excluded".
    'blocklist' => [
        'Connells',
        'haart',
        'Your Move',
        'Knight Frank',
        'Purplebricks',
        'Foxtons',
        'Savills',
        'Hunters',
        'William H Brown',
        'Bairstow Eves',
        'Reeds Rains',
    ],

    // Login rate limit: this many failed attempts per IP in the window locks the IP out.
    'login_max_attempts'  => 5,
    'login_window_minutes' => 15,

    // Import: rows processed per request while a large CSV is being imported.
    'import_chunk_size' => 500,
];
