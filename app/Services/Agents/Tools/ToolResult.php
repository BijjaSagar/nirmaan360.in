<?php

namespace App\Services\Agents\Tools;

final readonly class ToolResult
{
    /**
     * @param  array<string,mixed>  $data  What the model reads.
     * @param  list<array<string,mixed>>  $sources  Records the data came from, with links built by the app.
     */
    public function __construct(
        public array $data,
        public array $sources = [],
    ) {}

    /** @return array{type:string,id:int,label:string,url:string,draft:bool} */
    public static function source(string $type, int $id, string $label, string $url, bool $draft = false): array
    {
        return ['type' => $type, 'id' => $id, 'label' => $label, 'url' => $url, 'draft' => $draft];
    }
}
