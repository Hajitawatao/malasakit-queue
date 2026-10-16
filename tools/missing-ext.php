<?php

// Prints "-d extension=..." flags for the extensions this PHP has not loaded.
// Used by _php.bat, so php.ini never has to be edited.
foreach (['zip', 'fileinfo', 'pdo_mysql', 'mbstring', 'openssl', 'curl'] as $ext) {
    if (! extension_loaded($ext)) {
        echo " -d extension={$ext}";
    }
}
