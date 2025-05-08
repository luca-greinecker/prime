<?php
/**
 * Script to display basic PHP environment information
 *
 * This script outputs:
 *  - PHP version
 *  - Operating system
 *  - Server API (SAPI)
 *  - Memory limit
 *  - Loaded extensions
 *  - php.ini configuration file path
 *  - And a full phpinfo() report (optional)
 */

// Basic info
echo "<h2>Basic PHP Info</h2>";
echo "<strong>PHP Version:</strong> " . phpversion() . "<br>";
echo "<strong>Operating System:</strong> " . PHP_OS . "<br>";
echo "<strong>Server API:</strong> " . php_sapi_name() . "<br>";
echo "<strong>Memory Limit:</strong> " . ini_get('memory_limit') . "<br>";
echo "<strong>Loaded Extensions:</strong> " . implode(', ', get_loaded_extensions()) . "<br>";
echo "<strong>php.ini Path:</strong> " . php_ini_loaded_file() . "<br>";

// Optional: display full phpinfo
// Uncomment the line below to see all configuration details
// phpinfo();
?>
