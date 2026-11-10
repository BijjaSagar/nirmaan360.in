<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The whole suite runs in one PHP process, so a signal handler a test leaves behind outlives its application
 * and goes off later inside another test. Tests\TestCase::tearDown takes them back and fails the test that left one.
 */
class SignalHandlersTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('pcntl_signal_get_handler')) {
            $this->markTestSkipped('Needs the pcntl extension.');
        }

        parent::setUp();
    }

    public function test_a_daemon_queue_worker_leaves_handlers_behind_and_they_are_taken_back(): void
    {
        // Late in the whole suite this process is over the worker's default memory limit of 128 MB, and the worker
        // would stop for that with exit code 12. So the limit is lifted; it is not part of what is tested.
        $this->artisan('queue:work', ['connection' => 'sync', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096])->assertSuccessful();

        // SIGALRM is the one that went off in a later test: PHP's own time limit uses it on macOS.
        $left = $this->forgetSignalHandlers();
        $this->assertContains('SIGALRM', $left);
        $this->assertContains('SIGTERM', $left);

        $this->assertSame([], $this->forgetSignalHandlers());
        $this->assertSame(SIG_DFL, pcntl_signal_get_handler(SIGALRM));
    }

    public function test_a_worker_run_with_once_leaves_nothing_behind(): void
    {
        $this->artisan('queue:work', ['connection' => 'sync', '--once' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertSame([], $this->forgetSignalHandlers());
    }
}
