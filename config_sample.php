<?php
// rename this file to config.php

/* 
optional add an .htaccess to further restrict access
<Files "config.php">
    Order allow,deny
    Deny from all
</Files>
 */

 if (!defined('DB_HOST'))   define('DB_HOST'  , 'localhost');
 if (!defined('DB_NAME'))   define('DB_NAME'  , 'your_database_name');
 if (!defined('DB_USER'))   define('DB_USER'  , 'your_username');
 if (!defined('DB_PASS'))   define('DB_PASS'  , 'your_password');

 if (!defined('EMAIL_HOST'))   define("EMAIL_HOST" ,"smtp host");
 if (!defined('EMAIL_USER')) define('EMAIL_USER', 'email addess');
 if (!defined('EMAIL_PASS')) define('EMAIL_PASS', 'email password');
 if (!defined('EMAIL_PORT'))   define('EMAIL_PORT' , 'SMTP outgoing port');

 if (!defined('PASSWORD'))  define('PASSWORD' , 'YourSecurePassword'); //Used for access to the Admin pages
 if (!defined('TZ_OFFSET')) define('TZ_OFFSET','+12:00'); //If you need to change timezones
 if (!defined('IS_DEMO'))   define('IS_DEMO'  , false);
 if (!defined('REFRESH'))   define('REFRESH'  , 30000); // 30000 = 30 seconds this is how often the main page will auto refresh
 if (!defined('RANDORDER')) define('RANDORDER', true); // Randomize the order of the locker items on the check page
 if (!defined('DEBUG'))     define('DEBUG'    , false); // Set to true to enable debugging


?>