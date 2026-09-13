<?php
header('Content-Type: text/plain');
echo "PHP_OK_" . date('Y-m-d H:i:s') . "\n";
echo "Server: " . php_uname('n') . "\n";
echo "PHP: " . phpversion() . "\n";
echo "CWD: " . getcwd() . "\n";
