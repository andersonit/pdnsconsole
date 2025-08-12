<?php
/**
 * PDNS Console
 * Copyright (c) 2025 Neowyze LLC
 *
 * Licensed under the Business Source License 1.0.
 * You may use this file in compliance with the license terms.
 *
 * License details: https://github.com/andersonit/pdnsconsole/blob/main/LICENSE.md
 */

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
