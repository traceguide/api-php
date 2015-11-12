<?php

class InitializationTest extends PHPUnit_Framework_TestCase {

    /*
        If data collection is started before the runtime is configuration is
        completed, the runtime should buffer that data until the init call.
     */
    public function testOutOfOrderInitializationDoesntFail() {

        $runtime = Traceguide::newRuntime(NULL, NULL);

        $runtime->infof("log000");

        $span = $runtime->startSpan();
        $span->setOperation("operation/000");
        $span->finish();

        $runtime->flush();

        $runtime->options(array(
            'group_name' => 'init_test_group',
            'access_token' => '1234567890',
        ));
        $runtime->flush();
    }

    public function testMultipleInitCalls() {

        $runtime = Traceguide::newRuntime(NULL, NULL);
        $this->assertGreaterThan(0, peek($runtime, "_options")['max_log_records']);
        $this->assertGreaterThan(0, peek($runtime, "_options")['max_span_records']);

        for ($i = 0; $i < 100; $i++) {
            $runtime->infof("log%03d", 3 * $i);

            // Redundant calls are fine as long as the configuration
            // is the same
            $runtime->options(array(
                'group_name'   => 'init_test_group',
                'access_token' => '1234567890',
            ));

           $runtime->infof("log%03d", 7 * $i);
        }
    }
}
