<?php

namespace App\Services\Agents;

final readonly class ModelResponse
{
    /**
     * @param  list<array<string,mixed>>  $content  Blocks exactly as returned. Thinking blocks must go back unchanged.
     * @param  array{input:int,output:int,cache_read:int,cache_write:int}  $usage  Exactly these four keys: the cost meter prices every key it finds.
     * @param  string|null  $model  The model that answered, as the provider names it.
     * @param  array<string,mixed>|null  $stopDetails  Sent with a refusal: its type, category and explanation.
     * @param  string|null  $requestId  The provider's id for this call, to quote when asking them about it.
     */
    public function __construct(
        public ?string $id,
        public ?string $stopReason,
        public array $content,
        public array $usage,
        public int $latencyMs,
        public ?string $model = null,
        public ?array $stopDetails = null,
        public ?string $requestId = null,
    ) {}

    public static function fromArray(array $body, int $latencyMs, ?string $requestId = null): self
    {
        $usage = (array) ($body['usage'] ?? []);

        return new self(
            $body['id'] ?? null,
            $body['stop_reason'] ?? null,
            array_values((array) ($body['content'] ?? [])),
            [
                'input' => (int) ($usage['input_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
                'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'cache_write' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            ],
            $latencyMs,
            is_string($body['model'] ?? null) ? $body['model'] : null,
            is_array($body['stop_details'] ?? null) ? $body['stop_details'] : null,
            filled($requestId) ? $requestId : null,
        );
    }

    public function text(): string
    {
        return implode('', array_map(
            fn (array $block) => $block['text'] ?? '',
            array_filter($this->content, fn (array $block) => ($block['type'] ?? '') === 'text'),
        ));
    }

    /** @return list<array<string,mixed>> */
    public function toolUses(): array
    {
        return array_values(array_filter($this->content, fn (array $block) => ($block['type'] ?? '') === 'tool_use'));
    }
}
