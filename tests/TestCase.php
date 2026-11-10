<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Signal handlers belong to the whole PHP process, not to one test's application. A handler left
     * behind still holds the application of the test that set it, which is flushed once that test ends.
     * When its signal arrives later, it runs in the middle of whatever test is going on then. A daemon
     * queue worker (queue:work without --once) leaves several, and the one for SIGALRM also takes over
     * PHP's own time limit. That is how a random late test failed with "Target class [config] does not exist".
     */
    protected function tearDown(): void
    {
        $left = [];

        try {
            parent::tearDown();
        } finally {
            $left = $this->forgetSignalHandlers();
        }

        if ($left !== []) {
            $this->fail('This test left a signal handler on the PHP process for '.implode(', ', $left).'. It would run later inside another test, against an application that is gone. In a test, run the queue worker with --once.');
        }
    }

    /**
     * Puts back the default for every signal that has a PHP handler, and cancels a pending alarm.
     *
     * @return list<string> The signals that had one.
     */
    protected function forgetSignalHandlers(): array
    {
        if (! function_exists('pcntl_signal_get_handler')) {
            return [];
        }

        $signals = [
            'SIGHUP' => SIGHUP, 'SIGINT' => SIGINT, 'SIGQUIT' => SIGQUIT, 'SIGUSR1' => SIGUSR1, 'SIGUSR2' => SIGUSR2,
            'SIGALRM' => SIGALRM, 'SIGTERM' => SIGTERM, 'SIGCHLD' => SIGCHLD, 'SIGCONT' => SIGCONT, 'SIGTSTP' => SIGTSTP,
        ];
        $left = [];

        foreach ($signals as $name => $signal) {
            if (! is_int(pcntl_signal_get_handler($signal))) {
                pcntl_signal($signal, SIG_DFL);
                $left[] = $name;
            }
        }

        // With the default back on SIGALRM, an alarm still pending would end the test run.
        if (in_array('SIGALRM', $left, true)) {
            pcntl_alarm(0);
        }

        return $left;
    }
}
