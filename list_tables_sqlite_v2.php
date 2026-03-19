<?php
$dbPath = '/home/danvizera/Projects/maiati/db/nobe/database/new_db.sqlite';
$sqlite = new SQLite3($dbPath);
$results = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table'");
while ($row = $results->fetchArray()) {
    echo $row['name'] . "\n";
}
