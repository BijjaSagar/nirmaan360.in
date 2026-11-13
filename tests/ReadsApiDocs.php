<?php

namespace Tests;

use Illuminate\Support\Str;

/**
 * Holds docs/API.md to the replies it describes, so a reader of this branch finds every key a route sends
 * and no pointer leads to a section that is not here.
 */
trait ReadsApiDocs
{
    /** The text of one `## ` section of docs/API.md, up to the next one. */
    protected function apiDocSection(string $heading): string
    {
        $doc = (string) file_get_contents(base_path('docs/API.md'));
        $this->assertStringContainsString("## {$heading}\n", $doc, "docs/API.md has no section \"{$heading}\".");

        return Str::before(Str::after($doc, "## {$heading}\n"), "\n## ");
    }

    /**
     * Every key of a reply, at any depth, is named in the section: as `key` in the text or as "key": in an
     * example. A list is checked through its first row.
     *
     * @param  array<array-key, mixed>  $reply
     */
    protected function assertDocumentsKeys(string $section, array $reply, string $path): void
    {
        foreach ($reply as $key => $value) {
            $at = is_string($key) ? "{$path}.{$key}" : $path;

            if (is_string($key)) {
                $this->assertTrue(
                    str_contains($section, "`{$key}`") || str_contains($section, "\"{$key}\":"),
                    "docs/API.md does not say what \"{$at}\" is.",
                );
            }

            if (is_array($value) && $value !== []) {
                $row = array_is_list($value) ? $value[0] : $value;
                $this->assertDocumentsKeys($section, is_array($row) ? $row : [], $at);
            }
        }
    }

    /** A pointer from docs/API.md to a numbered section of the app contract lands on a heading in this tree. */
    protected function assertContractPointersResolve(): void
    {
        $doc = (string) file_get_contents(base_path('docs/API.md'));
        $contract = (string) file_get_contents(base_path('mobile/docs/CONTRACT.md'));
        preg_match_all('/CONTRACT\.md`?,?\s+section\s+(\d+)/i', $doc, $matches);

        foreach ($matches[1] as $number) {
            $this->assertMatchesRegularExpression("/^## {$number}\\./m", $contract, "docs/API.md points to section {$number} of mobile/docs/CONTRACT.md, which is not on this branch.");
        }
    }
}
