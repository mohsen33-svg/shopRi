<?php
require __DIR__.'/../src/lib.php';
db()->exec(file_get_contents(__DIR__.'/../db/schema.sql'));echo "Migration OK\n";
