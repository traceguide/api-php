<?php
namespace TraceguideBase\Client\Transports;

class TransportUDP {

    const MAX_MESSAGE_BYTES = 65535;

    protected $_sock = null;
    protected $_host = null;
    protected $_post = null;

    public function ensureConnection($options) {
        $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) {
            return;
        }

        $this->_sock = $sock;
        $this->_host = $options['service_host'];
        $this->_port = $options['service_port'];
    }

    public function flushReport($auth, $report) {
        if (!$this->_sock) {
            return;
        }
        if (is_null($auth) || is_null($report)) {
            return;
        }

        // The UDP payload is encoded in a function call like container that
        // maps to the Thrift named arguments.
        //
        // Note: a trade-off is being made here to reduce code path divergence
        // from the "standard" RPC mechanism at the expense of some overhead in
        // creating intermediate Thrift data structures and JSON encoding.
        $data = array(
            'auth' => $auth,
            'report' => $report,
        );

        // Prefix with a header for versioning and routing purposes to future
        // proof for other RPC calls.
        // The format is  /<version>/<service>/<function_name>?<json_payload>
        $msg = "/v1/crouton/report?" . json_encode($data);
        if (!is_string($msg)) {
            throw new \Exception('Could not encode report');
        }
        $msg = gzencode($msg);
        $len = strlen($msg);

        // Drop messages that are going to fail due to UDP size constraints
        if ($len > self::MAX_MESSAGE_BYTES) {
            return;
        }
        $bytesSent = @socket_sendto($this->_sock, $msg, strlen($msg), 0, $this->_host, $this->_port);

        // Reset the connection if something went amiss
        if ($bytesSent === FALSE) {
            socket_close($this->_sock);
            $this->_sock = null;
        }

        // By design, the UDP transport never returns a valid Thrift response
        // object
        return null;
    }
}
