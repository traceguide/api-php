<?php
namespace TraceguideBase\Client;

require_once(dirname(__FILE__) . "/../api.php");
require_once(dirname(__FILE__) . "/ClientSpan.php");
require_once(dirname(__FILE__) . "/NoOpSpan.php");
require_once(dirname(__FILE__) . "/Util.php");
require_once(dirname(__FILE__) . "/../../thrift/CroutonThrift/Types.php");
require_once(dirname(__FILE__) . "/../../thrift/CroutonThrift/ReportingService.php");

/**
 * Main implementation of the Runtime interface
 */
class ClientRuntime implements \TraceguideBase\Runtime {

    protected $_util = null;
    protected $_options = array();
    protected $_enabled = true;

    protected $_guid = "";
    protected $_startTime = 0;
    protected $_thriftAuth = null;
    protected $_thriftRuntime = null;
    protected $_thriftClient = null;

    protected $_reportStartTime = 0;
    protected $_logRecords = array();
    protected $_spanRecords = array();
    protected $_counters = array(
        'dropped_logs' => 0,
        'dropped_counters' => 0,
    );

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
        $this->_startTime = $this->_util->nowMicros();
        $this->_reportStartTime = $this->_startTime;

        $this->options($options);

        // PHP is (in many real-world contexts) single-threaded and
        // does not have an event loop like Node.js.  Flush on exit.
        $runtime = $this;
        register_shutdown_function(function() use ($runtime) {
            $runtime->flush();
        });
    }

    public function __destruct() {
        $this->flush();
    }

    public function options($options) {

        // Deferred group name / access token initialization is supported (i.e.
        // it is possible to create logs/spans before setting this info).
        if (isset($options['access_token']) && isset($options['group_name'])) {
            $this->_initThriftIfNeeded($options['group_name'], $options['access_token']);
        }
    }

    private function _initThriftIfNeeded($groupName, $accessToken) {

        if (!is_string($accessToken)) {
            throw new \Exception('access_token must be a string');
        }
        if (!is_string($groupName)) {
            throw new \Exception('group_name must be a string');
        }

        // Potentially redundant initialization info: only complain if
        // it is inconsistent.
        if ($this->_thriftAuth != NULL || $this->_thriftRuntime != NULL) {
            if ($this->_thriftAuth->access_token !== $accessToken) {
                throw new \Exception('access_token cannot be changed after it is set');
            }
            if ($this->_thriftRuntime->group_name !== $groupName) {
                throw new \Exception('group_name cannot be changed after it is set');
            }
            return;
        }

        $this->_thriftAuth = new \CroutonThrift\Auth(array(
            'access_token' => $accessToken,
        ));
        $this->_thriftRuntime = new \CroutonThrift\Runtime(array(
            'guid' => $this->_guid,
            'start_micros' => $this->_startTime,
            'group_name' => $groupName,
        ));
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
        if (!$this->_enabled) {
            return;
        }

        $now = $this->_util->nowMicros();
        if ($now >= $this->_nextFlushMicros) {
            $this->flush();
        }
    }

    public function flush() {
        if (!$this->_enabled) {
            return;
        }

        $now = $this->_util->nowMicros();
        $this->_nextFlushMicros = $now + $this->_flushPeriodMicros;

        // The thrift configuration has not yet been set: allow logs and spans
        // to be buffered in this case, but flushes won't yet be possible.
        if ($this->_thriftRuntime == NULL) {
            return;
        }

        if (count($this->_logRecords) == 0 && count($this->_spanRecords) == 0) {
            return;
        }
        $this->ensureConnection();

        // Convert the counters to thrift form
        $thriftCounters = array();
        foreach ($this->_counters as $key => $value) {
            array_push($thriftCounters, new \CroutonThrift\NamedCounter(array(
                'Name' => $key,
                'Value' => $value,
            )));
        }
        $reportRequest = new \CroutonThrift\ReportRequest(array(
            'runtime'         => $this->_thriftRuntime,
            'oldest_micros'   => $this->_reportStartTime,
            'youngest_micros' => $now,
            'log_records'     => $this->_logRecords,
            'span_records'    => $this->_spanRecords,
            'counters'        => $thriftCounters,
        ));

        $resp = null;
        try {
            $resp = $this->_thriftClient->Report($this->_thriftAuth, $reportRequest);

            // Only clear the buffers and reset the data if the Report() did not throw
            // an exception
            $this->_reportStartTime = $now;
            $this->_logRecords = array();
            $this->_spanRecords = array();
            foreach ($this->_counters as &$value) {
                $value = 0;
            }

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
        $full = $this->pushWithMax($this->_spanRecords, $span->toThrift(), $this->_options["max_span_records"]);
        if ($full) {
            $this->_counters['dropped_spans']++;
        }

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
        $full = $this->pushWithMax($this->_logRecords, $rec, $this->_options["max_log_records"]);
        if ($full) {
            $this->_counters['dropped_logs']++;
        }
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
            return true;
        } else {
            return false;
        }
    }
}
