<?php
spl_autoload_register(function($cls){
    $base = __DIR__;
    $paths = [
        "$base/lib/$cls.php",
        "$base/$cls.php"
    ];
    foreach ($paths as $p) {
        if (is_readable($p)) { require_once $p; return; }
    }
});
