<?php

require_once(__DIR__ . '/client/ClientRuntime.php');

class Traceguide {

    /**
     * The singleton instance of the runtime.
     */
    private static $_singleton;

    /**
     * Initializes and returns the singleton instance of the Runtime.
     *
     * @throws Exception if the group name or access token is not a valid string
     * @throws Exception if the runtime singleton has already been initialized
     */
    public static function initialize($group_name, $access_token, $opts = null) {
        if (isset(self::$_singleton)) {
            throw new Exception('Instrumentation library already initialized');
        }

        self::$_singleton = self::newRuntime($group_name, $access_token, $opts);
        return self::$_singleton;
    }

    /**
     * Returns the singleton instance of the Runtime.
     *
     * For convenience, this function can be passed the  $group_name and 
     * $access_token parameters to also initialize the runtime singleton. These
     * values will be ignored on any calls after the first to getInstance(). 
     * 
     * @param $group_name Group name to use for the runtime
     * @param $access_token The project access token
     * @return Runtime
     * @throws Exception if the group name or access token is not a valid string
     */
    public static function getInstance($group_name = NULL, $access_token = NULL, $opts = NULL) {
        if (!isset(self::$_singleton)) {
            self::$_singleton = self::newRuntime($group_name, $access_token, $opts);
        }
        return self::$_singleton;
    }

    /**
     * Creates a new runtime instance.
     *
     * @param $group_name Group name to use for the runtime
     * @param $access_token The project access token     
     * @return Runtime
     * @throws Exception if the group name or access token is not a valid string.
     */
    public static function newRuntime ($group_name, $access_token, $opts = NULL) {
        if (is_null($opts)) {
            $opts = array();
        }
        $opts['group_name'] = $group_name;
        $opts['access_token'] = $access_token;

        if (!is_string($opts['group_name']) || strlen($opts['group_name']) == 0) {
            throw new Exception("Invalid group_name");
        }
        if (!is_string($opts['access_token']) || strlen($opts['access_token']) == 0) {
            throw new Exception("Invalid access_token");
        }        

        return new Traceguide\Client\ClientRuntime($opts);
    }

    /*
     * Runtime API
     */


    public static function startSpan() {
        return self::getInstance()->startSpan();
    }

    public static function infof($fmt) {
        self::getInstance()->_log('I', $fmt, func_get_args());
    }

    public static function warnf($fmt) {
        self::getInstance()->_log('W', $fmt, func_get_args());
    }

    public static function errorf($fmt) {
        self::getInstance()->_log('E', $fmt, func_get_args());
    }

    public static function fatalf($fmt) {
        self::getInstance()->_log('F', $fmt, func_get_args());
    }

    public static function flush() {
        self::getInstance()->flush();
    }

    public static function disable() {
        self::getInstance()->disable();
    }

};
