<?php

namespace App\Core\Documents\Scanning;

/**
 * ADR-796.1 — ClamAV daemon (clamd) over its INSTREAM protocol (unix socket or tcp). Answers :
 * "stream: OK" → clean ; "stream: <sig> FOUND" → infected ; anything else (connection refused, timeout,
 * size limit, unknown answer) → error. Not installed on DEV : the driver is ready, never simulated.
 */
final class ClamAvScanner implements FileScanner
{
    private const CHUNK = 8192;

    public function __construct(private string $socket, private int $timeout = 30) {}

    public function engine(): string
    {
        return 'clamav';
    }

    public function scan(string $absolutePath): ScanResult
    {
        $in = @fopen($absolutePath, 'rb');
        if ($in === false) {
            return ScanResult::error($this->engine(), 'file_unreadable');
        }
        $errno = 0;
        $errstr = '';
        $conn = @stream_socket_client($this->socket, $errno, $errstr, $this->timeout);
        if ($conn === false) {
            fclose($in);

            return ScanResult::error($this->engine(), 'engine_unreachable');
        }
        try {
            stream_set_timeout($conn, $this->timeout);
            fwrite($conn, "zINSTREAM\0");
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($conn, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($conn, pack('N', 0));
            $answer = trim((string) stream_get_contents($conn), "\0\r\n ");
            if (stream_get_meta_data($conn)['timed_out'] ?? false) {
                return ScanResult::error($this->engine(), 'engine_timeout');
            }
        } finally {
            fclose($conn);
            fclose($in);
        }
        if (preg_match('/^stream: OK$/', $answer)) {
            return ScanResult::clean($this->engine());
        }
        if (preg_match('/^stream: (.+) FOUND$/', $answer, $m)) {
            return ScanResult::infected($this->engine(), $m[1]);
        }

        return ScanResult::error($this->engine(), 'engine_answer_unexpected');
    }
}
