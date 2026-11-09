<?php

namespace Tests\Feature\Drawings;

use App\Models\Drawing;
use App\Models\DrawingSheet;
use App\Models\DrawingVersion;
use App\Models\Project;
use App\Models\TakeoffLayer;

trait BuildsDrawings
{
    protected function makeDrawing(Project $project, string $number = 'A-101', string $revision = 'R0', string $mime = 'application/pdf'): Drawing
    {
        $drawing = Drawing::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'kind' => 'drawing',
            'number' => $number,
            'title' => 'Ground floor plan',
        ]);

        $this->makeVersion($drawing, $revision, $mime);

        return $drawing;
    }

    protected function makeVersion(Drawing $drawing, string $revision, string $mime = 'application/pdf'): DrawingVersion
    {
        DrawingVersion::where('drawing_id', $drawing->id)->update(['is_current' => false]);

        return DrawingVersion::create([
            'organization_id' => $drawing->organization_id,
            'project_id' => $drawing->project_id,
            'drawing_id' => $drawing->id,
            'version_no' => (int) DrawingVersion::where('drawing_id', $drawing->id)->max('version_no') + 1,
            'revision' => $revision,
            'status' => 'for_construction',
            'path' => 'org-'.$drawing->organization_id.'/drawings/'.$revision.'.pdf',
            'original_name' => $revision.'.pdf',
            'mime' => $mime,
            'size_bytes' => 1024,
            'is_current' => true,
        ]);
    }

    protected function makeSheet(DrawingVersion $version, int $page = 1, string $width = '1000', string $height = '800', ?string $metresPerUnit = null): DrawingSheet
    {
        return DrawingSheet::create([
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'drawing_version_id' => $version->id,
            'page_no' => $page,
            'width' => $width,
            'height' => $height,
            'metres_per_unit' => $metresPerUnit,
        ]);
    }

    /** A 1000 x 800 sheet where one sheet unit is exactly 0.1 m. */
    protected function scaledSheet(Project $project): DrawingSheet
    {
        return $this->makeSheet($this->makeDrawing($project)->currentVersion, metresPerUnit: '0.100000000000');
    }

    protected function makeLayer(Project $project, string $name = 'Brickwork'): TakeoffLayer
    {
        return TakeoffLayer::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => $name,
            'colour' => '#b4451f',
        ]);
    }
}
