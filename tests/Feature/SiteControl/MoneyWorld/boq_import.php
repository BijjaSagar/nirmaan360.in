<?php

use App\Services\Imports\BoqSheetImport;

/*
 * A BOQ file the owner has uploaded from the field app and not yet confirmed, priced at the BOQ rate, so the
 * check the app reads (GET /projects/{project}/boq-imports/{token}) carries rupee figures. A preview belongs to
 * the person who uploaded it: the owner's walk gets it, and the site roles, who may not manage the BOQ, are refused.
 */
return function (): void {
    $path = tempnam(sys_get_temp_dir(), 'walk').'.csv';
    file_put_contents($path, "section,code,description,unit,qty,rate\nWalk,W1,Line for the walk,cum,2,".self::MONEY['boq_rate']."\n");

    $staged = app(BoqSheetImport::class)->stage($this->project, $this->users['owner'], $path, 'walk.csv');
    @unlink($path);

    $this->routeParams['api.boq-imports.show'] = ['token' => $staged['token']];
};
