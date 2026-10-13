<?php

// A one-connection stand-in for the ClamAV daemon, for tests. Usage: php fake-clamd.php "<answer>"
// Prints the port it listens on (line 1), then after the request the number of file bytes it received (line 2).
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
fwrite(STDOUT, substr(strrchr(stream_socket_get_name($server, false), ':'), 1)."\n");
fflush(STDOUT);
$conn = @stream_socket_accept($server, 10);
if (! $conn) {
    exit(1);
}
$buffer = '';
while (! feof($conn)) {
    $data = fread($conn, 8192);
    if ($data === '' || $data === false) {
        break;
    }
    $buffer .= $data;
    if (str_ends_with($buffer, pack('N', 0))) {
        break;
    }
}
$command = substr($buffer, 0, 10);
$bytes = 0;
$offset = 10;
while ($offset + 4 <= strlen($buffer)) {
    $len = unpack('N', substr($buffer, $offset, 4))[1];
    $bytes += $len;
    $offset += 4 + $len;
}
fwrite($conn, ($argv[1] ?? 'stream: OK')."\0");
fclose($conn);
fwrite(STDOUT, trim($command, "\0").' '.$bytes."\n");
