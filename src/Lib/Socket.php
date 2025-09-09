<?php

namespace SmsDaemon\Lib;

use \Exception;
use \Socket as SocketResource;

/**
 * Class Socket
 *
 * A wrapper for PHP's low-level socket functions to provide a more
 * object-oriented and exception-based interface.
 */
class Socket
{
    /** @var SocketResource|resource|null */
    private $socket;

    /**
     * Socket constructor.
     * @param string $type The type of socket ('tcp', 'tcp6', 'udp').
     * @param float $timeout The socket timeout in seconds.
     * @throws Exception If the socket type is invalid or creation fails.
     */
    public function __construct(string $type, float $timeout = 3.0)
    {
        $domain = AF_INET;
        $socketType = SOCK_STREAM;
        $protocol = SOL_TCP;

        switch ($type) {
            case 'tcp':
                break; // Defaults are for TCP
            case 'tcp6':
                $domain = AF_INET6;
                break;
            case 'udp':
                $socketType = SOCK_DGRAM;
                $protocol = SOL_UDP;
                break;
            default:
                throw new Exception("Invalid socket type: {$type}");
        }

        $this->socket = @socket_create($domain, $socketType, $protocol);
        $this->checkError();

        $timeout_sec = floor($timeout);
        $timeout_usec = (int)(($timeout - $timeout_sec) * 1000000);

        @socket_set_option(
            $this->socket,
            SOL_SOCKET,
            SO_RCVTIMEO,
            ['sec' => $timeout_sec, 'usec' => $timeout_usec]
        );
        $this->checkError();
    }

    /**
     * Connects to a remote address.
     * @param string $address The IP address.
     * @param int $port The port.
     * @return bool True on success, false on failure.
     */
    public function connect(string $address, int $port): bool
    {
        $result = @socket_connect($this->socket, $address, $port);
        return $result !== false;
    }

    /**
     * Writes data to the socket.
     * @param string $data The data to write.
     * @return int The number of bytes written.
     * @throws Exception On socket write error.
     */
    public function write(string $data): int
    {
        $bytesWritten = @socket_write($this->socket, $data, strlen($data));
        if ($bytesWritten === false) {
            $this->checkError();
        }
        return $bytesWritten;
    }

    /**
     * Reads data from the socket.
     * @param int $length Max bytes to read. If 0, reads until timeout.
     * @return string The data read from the socket.
     */
    public function read(int $length = 2048): string
    {
        $buffer = '';
        if ($length > 0) {
            $chunk = @socket_read($this->socket, $length);
            $buffer = ($chunk === false) ? '' : $chunk;
        } else { // Read until timeout
            while (true) {
                $chunk = @socket_read($this->socket, 2048);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buffer .= $chunk;
            }
        }

        if ($buffer === '') {
            $this->checkError(false); // Check for a real error, but don't throw on timeout
        }

        return $buffer;
    }

    /**
     * Closes the socket connection.
     */
    public function close(): void
    {
        if ($this->socket instanceof SocketResource || is_resource($this->socket)) {
            @socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Checks for the last socket error and throws an exception if found.
     * @param bool $throw Whether to throw an exception on error.
     * @throws Exception If a socket error occurred.
     */
    private function checkError(bool $throw = true)
    {
        $socketResource = ($this->socket instanceof SocketResource || is_resource($this->socket)) ? $this->socket : null;
        $errorCode = socket_last_error($socketResource);

        if ($errorCode) {
            $errorMsg = socket_strerror($errorCode);
            @socket_clear_error($socketResource);
            if ($throw) {
                throw new Exception("Socket error [{$errorCode}]: {$errorMsg}");
            }
        }
    }

    /**
     * Destructor to ensure the socket is closed.
     */
    public function __destruct()
    {
        $this->close();
    }
}
