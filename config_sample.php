<?php
// rename this file to config.php
/* 
optional add an .htaccess to further restrict access
<Files "config.php">
    Order allow,deny
    Deny from all
</Files>
 */

define('db_host' ,'localhost');
define('db_name' , 'your_database_name');
define('db_user' , 'your_username');
define('db_pass','your_password');
define('PASSWORD', 'YourSecurePassword');

define('is_demo',false);

?>