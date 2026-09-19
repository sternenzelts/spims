<?php

namespace App\Domain\Parts;

use App\Models\Part;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Spare part search (PRD FR-SPIMS-001, NFR-SPIMS-006): an exact or leading part number, or words
 * anywhere in the description, ignoring case. An exact part-number match sorts first. Served by the
 * indexes parts_part_number_prefix_idx and parts_description_trgm.
 */
final class PartSearch
{
    /**
     * @return Builder<Part>
     */
    public function matching(string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return Part::query()->orderBy('part_number');
        }

        $lower = Str::lower($term);

        return Part::query()
            ->where(fn ($query) => $query
                ->whereRaw('lower(part_number) like ?', [SearchTerm::startsWith($lower)])
                ->orWhere('description', 'ilike', SearchTerm::contains($term)))
            ->orderByRaw('lower(part_number) = ? desc', [$lower])
            ->orderBy('part_number');
    }
}
