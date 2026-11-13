<?php

namespace Tests\Feature\VoiceDpr\Support;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/**
 * For a test of another module that needs the app as it was before voice DPR was deployed: its
 * routes are taken out, so the voice route no longer counts as a deployed reader, and its config is
 * emptied, so its text call purpose and its offer to the field app are gone.
 */
trait WithoutVoiceDpr
{
    protected function withoutVoiceDpr(): void
    {
        $routes = new RouteCollection;

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.dpr-voice-drafts.')) {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
        config(['modules.voice_dpr' => []]);
    }
}
