<?php

namespace App\Services\Agents;

use App\Exceptions\ImmutableRecordException;
use App\Models\AgentActionRequest;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\AgentToolCall;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\Tools\Tool;
use App\Services\Agents\Tools\ToolContext;
use App\Services\Agents\Tools\ToolFailure;
use App\Services\Agents\Tools\ToolRefused;
use App\Services\ApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Every tool call passes through here: checked, run, logged and audited. */
class ToolRunner
{
    /** People who can allow an agent's action. Owners and admins can always act as well. */
    private const APPROVER_ROLES = ['pm'];

    public function __construct(
        private ToolRegistry $registry,
        private SchemaValidator $validator,
        private Guardrails $guardrails,
        private ApprovalService $approvals,
    ) {}

    /** @param array{id:string,name:string,input:array<string,mixed>} $block A tool_use block from the model. */
    public function call(AgentRun $run, AgentStep $step, array $block, User $user, Organization $organization): AgentToolCall
    {
        $input = (array) ($block['input'] ?? []);

        $call = AgentToolCall::firstOrCreate(
            ['agent_run_id' => $run->id, 'tool_use_id' => $block['id']],
            [
                'organization_id' => $run->organization_id,
                'agent_step_id' => $step->id,
                'tool' => mb_substr((string) $block['name'], 0, 80),
                'status' => AgentToolCall::PENDING,
                'input' => $this->guardrails->redactDeep($input),
            ],
        );

        // A second delivery of the same call must not run it twice.
        if ($call->status !== AgentToolCall::PENDING) {
            return $call;
        }

        $tool = $this->registry->find($call->tool);
        $allowed = $run->agentVersion->tools;

        if (! in_array($call->tool, $allowed, true)) {
            return $this->refuse($run, $call, 'not_in_tool_list', "{$call->tool} is not on this agent's tool list.");
        }

        if ($tool === null) {
            return $this->refuse($run, $call, 'unknown_tool', "{$call->tool} is not a registered tool.");
        }

        if ($blocked = $this->registry->blockedBy($tool, $run->autonomy, $user, $organization)) {
            return $this->refuse($run, $call, $blocked[0], $blocked[1]);
        }

        if ($errors = $this->validator->errors($input, $tool->schema())) {
            return $this->fail($run, $call, 'The input was not valid: '.implode(' ', $errors));
        }

        $context = new ToolContext($run, $user, $call->tool_use_id);

        return $tool->effect() === Tool::APPROVAL
            ? $this->requestApproval($run, $call, $tool, $input, $context, $user)
            : $this->execute($run, $call, $tool, $input, $context);
    }

    /** Runs a call a person has approved, after checking the user may still do it. */
    public function runApproved(AgentRun $run, AgentToolCall $call, AgentActionRequest $request, User $user, Organization $organization): AgentToolCall
    {
        $tool = $this->registry->find($call->tool);

        if ($tool === null) {
            return $this->refuse($run, $call, 'unknown_tool', "{$call->tool} is not a registered tool.");
        }

        if ($blocked = $this->registry->blockedBy($tool, $run->autonomy, $user, $organization)) {
            return $this->refuse($run, $call, $blocked[0], $blocked[1]);
        }

        return $this->execute($run, $call, $tool, $request->input, new ToolContext($run, $user, $call->tool_use_id));
    }

    /** @return array<string,mixed> The tool_result block for the Messages API. */
    public function resultBlock(AgentToolCall $call): array
    {
        if ($call->status === AgentToolCall::OK) {
            return [
                'type' => 'tool_result',
                'tool_use_id' => $call->tool_use_id,
                'content' => $this->guardrails->wrap('tool:'.$call->tool, [
                    'data' => $call->output,
                    'sources' => array_map(fn (array $source) => ['type' => $source['type'], 'id' => $source['id'], 'label' => $source['label']], $call->sources ?? []),
                ]),
            ];
        }

        return [
            'type' => 'tool_result',
            'tool_use_id' => $call->tool_use_id,
            'is_error' => true,
            'content' => (string) $call->error,
        ];
    }

    private function execute(AgentRun $run, AgentToolCall $call, Tool $tool, array $input, ToolContext $context): AgentToolCall
    {
        $started = now();

        try {
            $result = $tool->effect() === Tool::READ ? $tool->run($input, $context) : DB::transaction(fn () => $tool->run($input, $context));
        } catch (ToolRefused $exception) {
            return $this->refuse($run, $call, $exception->reason, $exception->getMessage());
        } catch (ToolFailure $exception) {
            return $this->fail($run, $call, $exception->getMessage());
        } catch (ValidationException $exception) {
            return $this->fail($run, $call, implode(' ', $exception->validator->errors()->all()));
        } catch (ImmutableRecordException) {
            return $this->fail($run, $call, 'That record is approved and cannot be changed.');
        } catch (Throwable $exception) {
            report($exception);

            return $this->fail($run, $call, 'The tool could not finish because of an internal error.');
        }

        // Source labels come from records too, and the model reads them.
        [$clean, $flags] = $this->guardrails->neutralizeDeep($this->guardrails->redactDeep(['data' => $result->data, 'sources' => $result->sources]));

        $call->update([
            'status' => AgentToolCall::OK,
            'output' => $clean['data'],
            'sources' => $clean['sources'],
            'injection_flags' => $flags ?: null,
            'latency_ms' => (int) $started->diffInMilliseconds(now()),
        ]);

        AuditLog::record('agent.tool_call', $run, null, ['tool' => $call->tool, 'status' => AgentToolCall::OK, 'tool_call_id' => $call->id]);

        if ($flags) {
            AuditLog::record('agent.injection_filtered', $run, null, ['tool' => $call->tool, 'patterns' => $flags, 'tool_call_id' => $call->id]);
        }

        return $call;
    }

    private function requestApproval(AgentRun $run, AgentToolCall $call, Tool $tool, array $input, ToolContext $context, User $user): AgentToolCall
    {
        try {
            $request = AgentActionRequest::create([
                'organization_id' => $run->organization_id,
                'agent_run_id' => $run->id,
                'agent_tool_call_id' => $call->id,
                'project_id' => $tool->actionProjectId($input, $context),
                'tool' => $tool->name(),
                'summary' => $this->guardrails->redact($tool->actionSummary($input, $context)),
                'input' => $input,
                'status' => 'DRAFT',
            ]);
        } catch (ToolRefused $exception) {
            return $this->refuse($run, $call, $exception->reason, $exception->getMessage());
        } catch (ToolFailure $exception) {
            return $this->fail($run, $call, $exception->getMessage());
        }

        $this->approvals->submit($request, $user, self::APPROVER_ROLES);

        $call->update(['status' => AgentToolCall::WAITING_APPROVAL]);
        AuditLog::record('agent.approval_requested', $run, null, ['tool' => $call->tool, 'tool_call_id' => $call->id, 'action_request_id' => $request->id]);

        return $call;
    }

    private function refuse(AgentRun $run, AgentToolCall $call, string $code, string $message): AgentToolCall
    {
        $call->update(['status' => AgentToolCall::REFUSED, 'refusal_code' => $code, 'error' => 'Refused: '.$message]);
        AuditLog::record('agent.tool_refused', $run, null, ['tool' => $call->tool, 'reason' => $code, 'tool_call_id' => $call->id]);

        return $call;
    }

    /** Failure messages can quote record names, so they get the same cleaning as tool output. */
    private function fail(AgentRun $run, AgentToolCall $call, string $message): AgentToolCall
    {
        [$clean] = $this->guardrails->neutralizeDeep($this->guardrails->redact($message));

        $call->update(['status' => AgentToolCall::ERROR, 'error' => $clean]);
        AuditLog::record('agent.tool_call', $run, null, ['tool' => $call->tool, 'status' => AgentToolCall::ERROR, 'tool_call_id' => $call->id]);

        return $call;
    }
}
