<?php
namespace Traceguide\Client;

require_once(dirname(__FILE__) . "/../api.php");
require_once(dirname(__FILE__) . "/ClientSpan.php");
require_once(dirname(__FILE__) . "/NoOpSpan.php");
require_once(dirname(__FILE__) . "/Util.php");
require_once(dirname(__FILE__) . "/../../thrift/CroutonThrift/Types.php");
require_once(dirname(__FILE__) . "/../../thrift/CroutonThrift/ReportingService.php");

/**
 * Main implementation of the Runtime interface
 */
class ClientRuntime implements \Traceguide\Runtime {

    protected $_util = null;
    protected $_options = array();
    protected $_enabled = true;

    protected $_guid = "";
    protected $_thriftAuth = null;
    protected $_thriftRuntime = null;
    protected $_thriftClient = null;

    protected $_logRecords = array();
    protected $_spanRecords = array();
    protected $_nextFlushMicros = 0;
    protected $_flushPeriodMicros = 0;

    public function __construct($options = array()) {
        $this->_util = new Util();

        $this->_options = array_merge(array(
            'service_host'          => 'api.traceguide.io',
            'service_port'          => 9998,

            'max_log_records'       => 1000,
            'max_span_records'      => 1000,
            'reporting_period_secs' => 5.0,

            // PHP-specific configuration
            //
            // TODO: right now any payload with depth greater than this is simply
            // rejected; it is not trimmed.
            'max_payload_depth'     => 10, 

        ), $options);

        $this->_flushPeriodMicros = $this->_options["reporting_period_secs"] * 1e6;
        $this->_nextFlushMicros = $this->_util->nowMicros() + $this->_flushPeriodMicros;

        $this->_guid = $this->_generateUUIDString();
        $this->_thriftAuth = new \CroutonThrift\Auth(array(
            'access_token' => $this->_options["access_token"],
        ));
        $this->_thriftRuntime = new \CroutonThrift\Runtime(array(
            'guid' => $this->_guid,
            'start_micros' => $this->_util->nowMicros(),
            'group_name' => $this->_options["group_name"],
        ));

        // PHP is (in many real-world contexts) single-threaded and
        // does not have an event loop like Node.js.  Flush on exit.
        $runtime = $this;
        register_shutdown_function(function() use ($runtime) {
            $runtime->flush();
        });
    }

    public function guid() {
        return $this->_guid;
    }

    public function disable() {
        $this->_discard();
        $this->_enabled = false;
    }

    /**
     * Internal use only.
     *
     * Discard all currently buffered data.  Useful for unit testing.
     */
    public function _discard() {
        $this->_logRecords = array();
        $this->_spanRecords = array();
    }

    public function startSpan() {
        if (!$this->_enabled) {
            return new NoOpSpan;
        }

        $span = new ClientSpan($this);
        $span->setStartMicros($this->_util->nowMicros());
        return $span;
    }

    public function infof($fmt) {
        if (!$this->_enabled) {
            return;
        }
        $this->_log('I', $fmt, func_get_args());
    }

    public function warnf($fmt) {
        if (!$this->_enabled) {
            return;
        }        
        $this->_log('W', $fmt, func_get_args());
    }

    public function errorf($fmt) {
        if (!$this->_enabled) {
            return;
        }        
        $this->_log('E', $fmt, func_get_args());
    }

    public function fatalf($fmt) {
        if (!$this->_enabled) {
            return;
        }        
        $text = $this->_log('F', $fmt, func_get_args());
        die($text);
    }  

    // PHP does not have an event loop or timer threads. Instead manually check as
    // new data comes in by calling this method.
    protected function flushIfNeeded() {
        $now = $this->_util->nowMicros();
        if ($now >= $this->_nextFlushMicros) {
            $this->flush();
        }
    }

    public function flush() {
        if (!$this->_enabled) {
            return;
        }

        $this->_nextFlushMicros = $this->_util->nowMicros() + $this->_flushPeriodMicros;

        if (count($this->_logRecords) == 0 && count($this->_spanRecords) == 0) {
            return;
        }
        $this->ensureConnection();

        $reportRequest = new \CroutonThrift\ReportRequest(array(
            'runtime' => $this->_thriftRuntime,
            'log_records' => $this->_logRecords,
            'span_records' => $this->_spanRecords,
        ));

        $resp = null;
        try {
            $resp = $this->_thriftClient->Report($this->_thriftAuth, $reportRequest);

            // Only clear the buffers if the Report() did not throw an exception
            $this->_logRecords = array();
            $this->_spanRecords = array();

        } catch (\Thrift\Exception\TTransportException $e) {
            // Release the client and so it will reconnect on the next attempt
            $this->_client = null;
        }

        // Process server response commands
        if (!is_null($resp) && is_array($resp->commands)) {
            foreach ($resp->commands as $cmd) {
                if ($cmd->disable) {
                    $this->disable();
                }
            }

        }
    }

    /**
     * Internal use only.
     *
     * Generates a random ID (not a *true* UUID)
     */
    public function _generateUUIDString() {
        return sprintf("%08x%08x%08x%08x",
            $this->_util->randInt32(),
            $this->_util->randInt32(),
            $this->_util->randInt32(),
            $this->_util->randInt32()
        );
    }

    /**
     * Internal use only.
     */
    public function _finishSpan(ClientSpan $span) {
        if (!$this->_enabled) {
            return;
        }

        $span->setEndMicros($this->_util->nowMicros());
        $this->pushWithMax($this->_spanRecords, $span->toThrift(), $this->_options["max_span_records"]);
        $this->flushIfNeeded();
    }

    /**
     * For internal use only.
     */
    public function _log($level, $fmt, $allArgs) {
        // The $allArgs variable contains the $fmt string
        array_shift($allArgs);
        $text = vsprintf($fmt, $allArgs);

        $this->_rawLogRecord(array(
            'level' => $level,
            'message' => $text,
            // TODO: capture args as payload
        ), $allArgs);

        $this->flushIfNeeded();
        return $text;
    }  

    /**
     * Internal use only.
     */
    public function _rawLogRecord($fields, $payloadArray) {
        if (!$this->_enabled) {
            return;
        }

        $fields = array_merge(array(
            'timestamp_micros' => $this->_util->nowMicros(),
            'runtime_guid' => $this->_guid,
        ), $fields);

        // TODO: data scrubbing and size limiting
        if (count($payloadArray) > 0) {
            // $json == FALSE on failure
            //
            // Examples that will cause failure:
            // - "Resources" (e.g. file handles)
            // - Circular references
            // - Exceeding the max depth (i.e. it *does not* trim, it rejects)
            //
            $json = json_encode($payloadArray, 0, $this->_options['max_payload_depth']);
            if (is_string($json)) {
                $fields["payload_json"] = $json;
            }
        }

        $rec = new \CroutonThrift\LogRecord($fields);
        $this->pushWithMax($this->_logRecords, $rec, $this->_options["max_log_records"]);
    }

    protected function ensureConnection() {
        if (!is_null($this->_thriftClient)) {
            return;
        }
        $this->_thriftClient = $this->createConnection();
    }

    protected function createConnection() {
        $host = $this->_options['service_host'];
        $port = $this->_options['service_port'];
        
        $socket = new \Thrift\Transport\THttpClient($host, $port, '/_rpc/v1/crouton/binary');
        $transport = new \Thrift\Transport\TBufferedTransport($socket, 1024, 1024);
        $protocol = new \Thrift\Protocol\TBinaryProtocol($transport);
        $client = new \CroutonThrift\ReportingServiceClient($protocol);

        return $client;
    }

    protected function pushWithMax(&$arr, $item, $max) {
        array_push($arr, $item);

        // Simplistic random discard
        $count = count($arr);
        if ($count > $max) {
            $i = $this->_util->randIntRange(0, $max - 1);
            $arr[$i] = array_pop($arr);
        }
    }
}

