<?php
$dbPath = '/home/danvizera/Projects/maiati/db/nobe/database/new_db.sqlite';
$sqlite = new SQLite3($dbPath);
$tables = ['guias', 'escrituracoes', 'ccms'];
foreach ($tables as $table) {
    echo "--- Table: $table ---\n";
    $results = $sqlite->query("PRAGMA table_info($table)");
    while ($row = $results->fetchArray()) {
        echo $row['name'] . " (" . $row['type'] . ")\n";
    }
    echo "\n";
}
