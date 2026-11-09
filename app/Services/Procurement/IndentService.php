<?php

namespace App\Services\Procurement;

use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\Indent;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IndentService
{
    public function __construct(
        private ApprovalService $approvals,
        private UnitConverter $units,
        private DocumentNumbers $numbers,
    ) {}

    public function rules(Project $project): array
    {
        return [
            'store_id' => ['bail', 'required', 'integer', Rule::exists('material_stores', 'id')->where('project_id', $project->id)],
            'required_by' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'client_uuid' => ProcurementRules::clientUuid(),
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'items.*.qty' => ProcurementRules::qty(),
            'items.*.unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
            'items.*.boq_item_id' => ['bail', 'nullable', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function create(Project $project, array $data, User $by): Indent
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? Indent::where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first() : null;

        if ($existing = $find()) {
            return $existing;
        }

        return Idempotent::create(function () use ($project, $data, $by) {
            $indent = Indent::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'store_id' => $data['store_id'],
                'number' => $this->numbers->next($project->organization_id, 'IND'),
                'required_by' => $data['required_by'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'status' => 'DRAFT',
                'client_uuid' => $data['client_uuid'] ?? null,
                'created_by' => $by->id,
            ]);

            $this->writeLines($indent, $data['items']);

            return $indent;
        }, $find);
    }

    /** Only draft and returned indents can change. */
    public function update(Indent $indent, array $data): Indent
    {
        return DB::transaction(function () use ($indent, $data) {
            $indent = Indent::lockForUpdate()->findOrFail($indent->id);

            if (! $indent->isEditable()) {
                throw ValidationException::withMessages(['status' => __('Only draft or returned indents can be edited.')]);
            }

            $indent->update([
                'store_id' => $data['store_id'],
                'required_by' => $data['required_by'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $indent->items()->delete();
            $this->writeLines($indent, $data['items']);

            return $indent;
        });
    }

    public function submit(Indent $indent, User $by): ?Approval
    {
        return DB::transaction(function () use ($indent, $by) {
            $indent = Indent::lockForUpdate()->findOrFail($indent->id);

            $hasOwnRule = ApprovalRule::where('organization_id', $indent->organization_id)
                ->where('doc_type', 'indent')
                ->where('active', true)
                ->exists();

            return $this->approvals->submit($indent, $by, $hasOwnRule ? [] : config('modules.procurement.indent_approval_levels', []));
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(Indent $indent, array $lines): void
    {
        $materials = Material::with('conversions')->whereIn('id', array_column($lines, 'material_id'))->get()->keyBy('id');

        foreach ($lines as $line) {
            $material = $materials->get($line['material_id']);

            if (! $material->is_active) {
                throw ValidationException::withMessages(['items' => __(':material is inactive and cannot be indented.', ['material' => $material->name])]);
            }

            $indent->items()->create([
                'organization_id' => $indent->organization_id,
                'material_id' => $material->id,
                'qty' => Dec::qty($line['qty']),
                'unit_id' => $line['unit_id'] ?? $material->base_unit_id,
                'base_qty' => $this->units->toBase($material, $line['qty'], $line['unit_id'] ?? null),
                'boq_item_id' => $line['boq_item_id'] ?? null,
                'remarks' => $line['remarks'] ?? null,
            ]);
        }

        AuditLog::record('indent.lines_saved', $indent, null, ['lines' => count($lines)]);
    }
}
