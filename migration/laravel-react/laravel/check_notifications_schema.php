<?php
$envPath = __DIR__ . '/.env';
$lines = file($envPath);
foreach ($lines as $line) {
    if (strpos($line, '=') && strpos(trim($line), '#') !== 0) {
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$conn_str = "host={$_ENV['DB_HOST']} port={$_ENV['DB_PORT']} dbname={$_ENV['DB_DATABASE']} user={$_ENV['DB_USERNAME']} password={$_ENV['DB_PASSWORD']}";
$connection = pg_connect($conn_str);

$result = pg_query($connection, "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'student_notifications' ORDER BY ordinal_position");
echo "Columns in student_notifications table:\n";
while ($row = pg_fetch_assoc($result)) {
    echo "  - {$row['column_name']} ({$row['data_type']})\n";
}
