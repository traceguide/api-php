<?php
namespace Traceguide\Client;

require_once(dirname(__FILE__) . "/Util.php");
require_once(dirname(__FILE__) . "/../../thrift/CroutonThrift/Types.php");

class ClientSpan implements \Traceguide\ActiveSpan {
    
    protected $_runtime = null;

    protected $_guid = "";
    protected $_operation = "";
    protected $_joinIds = array();
    protected $_attributes = array();
    protected $_startMicros = 0;
    protected $_endMicros = 0;

    public function __construct($runtime) {
        $this->_runtime = $runtime;
        $this->_guid = $runtime->_generateUUIDString();
    }

    public function __destruct() {
        // Use $_endMicros as a indicator this span has not been finished
        if ($this->_endMicros == 0) {
            $this->warnf("finish() never closed on span (operaton='%s')", $this->_operation, $this->_joinIds);
            $this->finish();
        }
    }

    public function guid() {
        return $this->_guid;
    }

    public function setStartMicros($start) {
        $this->_startMicros = $start;
        return $this;
    }

    public function setEndMicros($start) {
        $this->_endMicros = $start;
        return $this;
    }

    public function finish() {
        $this->_runtime->_finishSpan($this);
    }

    public function setOperation($name) {
        $this->_operation = $name;
        return $this;
    }

    // TODO: along with "addAttribute", given the implementation 
    public function addTraceJoinId($key, $value) {
        $this->_joinIds[$key] = $value;   
        return $this;
    }

    public function setEndUserId($id) {
        $this->addTraceJoinId(TRACEGUIDE_JOIN_KEY_END_USER_ID, $id);
        return $this;
    }

    // TODO: this is implemented as a "setAttribute" but named "addAttribute"
    // for consistency with the Go API...which currently also implements it
    // as if it were a "set", not "add" operation.
    public function addAttribute($key, $value) {
        $this->_attributes[$key] = $value;
        return $this;
    }

    public function setParent($span) {
        return $this->addAttribute("parent_span_guid", $span->guid());
    }

    public function infof($fmt) {
        $this->_log('I', $fmt, func_get_args());
        return $this;
    }

    public function warnf($fmt) {
        $this->_log('W', $fmt, func_get_args());
        return $this;
    }

    public function errorf($fmt) {
        $this->_log('E', $fmt, func_get_args());
        return $this;
    }

    public function fatalf($fmt) {
        $text = $this->_log('F', $fmt, func_get_args());
        die($text);
    }

    protected function _log($level, $fmt, $allArgs) {
        // The $allArgs variable contains the $fmt string
        array_shift($allArgs);
        $text = vsprintf($fmt, $allArgs);

        $this->_runtime->_rawLogRecord(array(
            'span_guid' => $this->_guid,
            'level' => $level,
            'message' => $text,
            // TODO: capture args as payload
        ), $allArgs);
        return $text;
    }

    public function toThrift() {

        $joinIds = array();
        foreach ($this->_joinIds as $key => $value) {
            $pair = new \CroutonThrift\TraceJoinId(array(
                "TraceKey" => $key,
                "Value"    => $value,
            ));
            array_push($joinIds, $pair);
        }

        $rec = new \CroutonThrift\SpanRecord(array(
            "runtime_guid" => $this->_runtime->guid(),
            "span_guid" => $this->_guid,
            "span_name" => $this->_operation,
            "oldest_micros" => $this->_startMicros,
            "youngest_micros" => $this->_endMicros,
            "join_ids" => $joinIds,
        ));
        return $rec;
    }
}

