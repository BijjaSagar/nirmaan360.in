<?php

namespace App\Http\Controllers\DocumentReading;

use Illuminate\Database\Eloquent\Model;

/**
 * For a route that names two records, one inside the other, such as a read and one of its pages.
 * Each record is found by its own id, so the route alone does not prove that the second belongs to
 * the first: without this check, a page of another read of the same company would be served under
 * this read's address and under this read's permissions.
 *
 * Call it first in every controller action that takes a parent and a child.
 */
trait ChecksParent
{
    /**
     * Answers 404 unless the child points at this parent.
     *
     * @param  ?string  $foreignKey  The child's column that holds the parent's id. By default the parent's own name: document_read_id for a DocumentRead.
     */
    protected function assertChildOf(Model $child, Model $parent, ?string $foreignKey = null): void
    {
        $pointsAt = $child->getAttribute($foreignKey ?? $parent->getForeignKey());

        abort_unless($pointsAt !== null && (string) $pointsAt === (string) $parent->getKey(), 404);
    }
}
