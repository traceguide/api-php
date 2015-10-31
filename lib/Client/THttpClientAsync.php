<?php
/*
 * This code in this file is forked from:
 * apache/thrift/lib/php/lib/Thrift/Transport/THttpClient.php
 * which is licensed under http://www.apache.org/licenses/LICENSE-2.0
 */
namespace TraceguideBase\Client;

use Thrift\Transport\TTransport;
use Thrift\Exception\TTransportException;
use Thrift\Factory\TStringFuncFactory;

/**
 * A custom THTTPClient that does an asynchronous HTTP POST in order to not
 * block the user thread.  The response from the request is NOT available when
 * using this TTransport.
 */
class THttpClientAsync extends TTransport {

    /**
     * Timeout for opening the persistent socket.
     */
    const DEFAULT_TIMEOUT_SECS = 1;

    /**
     * On a socket open failure, number of times to retry regardless of the
     * error.
     */
    const MAX_SOCKET_OPEN_RETRIES = 1;

    /**
     * Max bytes per single write to the socket.
     */
    const MAX_BYTES_PER_WRITE = 8192;

    /**
     * The host to connect to
     *
     * @var string
     */
    protected $host_;

    /**
     * The port to connect on
     *
     * @var int
     */
    protected $port_;

    /**
     * The URI to request
     *
     * @var string
     */
    protected $uri_;

    /**
     * The scheme to use for the request, i.e. http, https
     *
     * @var string
     */
    protected $scheme_;

    /**
    * Buffer for the HTTP request data
    *
    * @var string
    */
    protected $buf_;

    /**
    * Read timeout
    *
    * @var float
    */
    protected $timeout_;

    /**
    * http headers
    *
    * @var array
    */
    protected $headers_;


    /**
     * Persistent socket
     * @var resource
     */
    protected $socket_;

    /**
     * Enable additional logging and runtime checks.
     * @var boolean
     */
    protected $debug_;

    /**
     * Make a new HTTP client.
     *
     * @param string $host
     * @param int    $port
     * @param string $uri
     */
    public function __construct($host, $port=80, $uri='', $secure = TRUE, $debug = FALSE) {
        if ((TStringFuncFactory::create()->strlen($uri) > 0) && ($uri{0} != '/')) {
          $uri = '/'.$uri;
        }
        $this->scheme_ = ($secure ? 'ssl' : 'tcp');
        $this->host_ = $host;
        $this->port_ = $port;
        $this->uri_ = $uri;
        $this->buf_ = '';
        $this->timeout_ = null;
        $this->headers_ = array();
        $this->socket_ = null;
        $this->debug_ = $debug;
    }

    public function __destruct() {
        $this->_closeSocket();
    }

    /**
    * Set read timeout
    *
    * @param float $timeout
    */
    public function setTimeoutSecs($timeout) {
        $this->timeout_ = $timeout;
    }

    /**
     * Whether this transport is open.
     *
     * @return boolean true if open
     */
    public function isOpen() {
        return true;
    }

    /**
     * Open the transport for reading/writing
     *
     * @throws TTransportException if cannot open
     */
    public function open() {}

    /**
     * Close the transport.
     */
    public function close() {
        $this->_closeSocket();
    }

    /**
    * Read some data into the array.
    *
    * @param int    $len How much to read
    * @return string The data that has been read
    * @throws TTransportException if cannot read any more data
    */
    public function read($len) {
        // The THttpClientAsync does not support reading back (the RPC responses)
        // need to be ignored if this Transport is used.
        return str_repeat("\0", $len);
    }

    /**
    * Writes some data into the pending buffer
    *
    * @param string $buf  The data to write
    * @throws TTransportException if writing fails
    */
    public function write($buf) {
        $this->buf_ .= $buf;
    }

    /**
    * Opens and sends the actual request over the HTTP connection
    *
    * @throws TTransportException if a writing error occurs
    */
    public function flush() {
        $headerHost = $this->host_.($this->port_ != 80 ? ':'.$this->port_ : '');

        $headers = array();
        $defaultHeaders = array('Host' => $headerHost,
                                'Accept' => 'application/x-thrift',
                                'User-Agent' => 'PHP/THttpClient',
                                'Content-Type' => 'application/x-thrift',
                                'Content-Length' => TStringFuncFactory::create()->strlen($this->buf_));
        foreach (array_merge($defaultHeaders, $this->headers_) as $key => $value) {
            $headers[] = "$key: $value";
        }

        $body = "POST " . $this->uri_ . " HTTP/1.1\r\n";
        foreach ($headers as $val) {
            $body .= "$val\r\n";
        }
        $body .= "\r\n";
        $body .= $this->buf_;

        if ($this->_ensureSocketCreated()) {
            $this->_writeStream($body);
        }
        $this->buf_ = '';
    }

    public function addHeaders($headers) {
        $this->headers_ = array_merge($this->headers_, $headers);
    }

    /**
     * Helper to write the given buffer to the persistent socket.
     */
    protected function _writeStream($buffer) {
        $fd = $this->socket_;
        $total = TStringFuncFactory::create()->strlen($buffer);

        // Early out on the trivial case
        if ($total <= 0) {
            return;
        }

        $failed = FALSE;
        $sent = 0;

        while (!$failed && $sent < $total) {
            try {
                // Supress any error messages as it is considered part of normal
                // operation for the write to fail on a broken pipe or timeout
                $written = @fwrite($fd, $buffer, self::MAX_BYTES_PER_WRITE);

                if ($written === FALSE) {

                    if ($this->debug_) {
                        error_log("Write failed.");
                    }
                    $failed = TRUE;

                } else if ($written > 0){

                    $sent += $written;
                    $buffer = substr($buffer, $written);

                } else if ($written === 0) {
                    if ($this->debug_) {
                        error_log("Zero bytes written to socket. sent=$sent total=$total");
                    }
                    $failed = TRUE;
                }

                if ($this->debug_) {
                    error_log("Written = $written bytes. Sent $sent of $total.");
                }

            } catch (Exception $e) {
                if ($this->debug_) {
                    error_log($e);
                }
                $failed = TRUE;
            }
        }

        if ($failed) {
            $this->_closeSocket();
        }
    }

    /**
     * Create the persistent socket connection if necessary.  Otherwise, do
     * nothing.
     */
    protected function _ensureSocketCreated() {
        // Already ready!
        if (is_resource($this->socket_)) {
            return TRUE;
        }

        $sockaddr = $this->scheme_ . '://' . $this->host_;
        $port = $this->port_;

        // Ignore the Thrift specified timeout ($this->timeout_) and use the
        // socket-specific timeout set in this file.
        $timeout = self::DEFAULT_TIMEOUT_SECS;

        for ($retry = 0; $retry < self::MAX_SOCKET_OPEN_RETRIES; $retry++) {
            try {
                // Suppress connection error logs
                $fd = @pfsockopen($sockaddr, $port, $errno, $errstr, $timeout);
                if ($errno == 0 && is_resource($fd)) {
                    // Connection okay - break out of the retry loop
                    $this->socket_ = $fd;
                    break;
                }
            } catch (Exception $e) {
                // Ignore the exception and retry
            }
        }

        return is_resource($this->socket_);
    }

    /**
     * Close the persistent connection. Note this does not necessarily close the
     * socket itself, as it is persisted, but the handle this process has to it.
     */
    protected function _closeSocket() {
        $fd = $this->socket_;
        if (is_resource($fd)) {
            $this->socket_ = null;
            @fclose($fd);
        }
    }
}
