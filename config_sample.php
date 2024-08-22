<?php
// rename this file to config.php
/* 
optional add an .htaccess to further restrict access
<Files "config.php">
    Order allow,deny
    Deny from all
</Files>
 */

define('DB_HOST' ,'localhost');
define('DB_NAME' , 'your_database_name');
define('DB_USER' , 'your_username');
define('DB_PASS','your_password');
define('PASSWORD', 'YourSecurePassword');

define('IS_DEMO',false);

?>