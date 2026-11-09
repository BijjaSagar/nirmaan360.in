<?php

namespace App\Services\Agents;

/** What an agent's final reply must look like. Only keywords the Messages API accepts are used here. */
final class OutputSchemas
{
    public static function answer(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['answer', 'clarifying_question', 'sources'],
            'properties' => [
                'answer' => ['type' => 'string'],
                'clarifying_question' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['type', 'id'],
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'id' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** The daily brief adds the decisions the owner needs to take. */
    public static function brief(): array
    {
        $schema = self::answer();
        $schema['required'][] = 'decisions';
        $schema['properties']['decisions'] = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['title', 'why'],
                'properties' => [
                    'title' => ['type' => 'string'],
                    'why' => ['type' => 'string'],
                ],
            ],
        ];

        return $schema;
    }
}
