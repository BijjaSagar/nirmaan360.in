<?php

namespace Tests\Feature\FieldOps;

use App\Services\FieldOps\Tone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Finds every piece of text this module shows, so each can be checked for a Hindi and a Marathi line. */
final class ModuleStrings
{
    /** Folders and files of each part. Text used by both parts belongs to "field_ops". */
    private const SOURCES = [
        'assets' => ['resources/views/modules/assets', 'app/Http/Controllers/Assets', 'app/Services/Assets', 'app/Http/Presenters/MachineLogPresenter.php'],
        'qhs' => ['resources/views/modules/qhs', 'app/Http/Controllers/Qhs', 'app/Services/Qhs', 'app/Http/Presenters/InspectionPresenter.php'],
        'field_ops' => ['resources/views/modules/field_ops', 'app/Services/FieldOps'],
    ];

    /** Config lists whose labels are shown through __() in the views. */
    private const CONFIG_LABELS = [
        'assets' => ['categories', 'ownerships', 'hire_bases', 'hire_types', 'meter_units', 'statuses'],
        'qhs' => ['ncr_categories', 'ncr_severities', 'ncr_statuses', 'root_causes', 'test_types', 'incident_kinds', 'incident_severities', 'injuries', 'incident_statuses', 'checklist_categories', 'inspection_results'],
    ];

    /**
     * @return array<string, list<string>> Each string, with the parts that use it.
     */
    public static function all(): array
    {
        $found = [];
        $add = function (string $text, string $part) use (&$found): void {
            if (trim($text) !== '' && ! in_array($part, $found[$text] ?? [], true)) {
                $found[$text][] = $part;
            }
        };

        foreach (self::SOURCES as $part => $paths) {
            foreach ($paths as $path) {
                foreach (self::files(base_path($path)) as $file) {
                    foreach (self::literals((string) file_get_contents($file)) as $text) {
                        $add($text, $part);
                    }
                }
            }
        }

        foreach (self::CONFIG_LABELS as $module => $keys) {
            foreach ($keys as $key) {
                foreach (config("modules.$module.$key") as $label) {
                    $add($label, $module);
                }
            }

            foreach (config("modules.$module.nav") as $item) {
                $add($item['label'], $module);

                if (isset($item['group'])) {
                    $add($item['group'], $module);
                }
            }
        }

        foreach (Tone::labels() as $label) {
            $add($label, 'field_ops');
        }

        ksort($found);

        return $found;
    }

    /** @return list<string> The text of every __('...') call in some source. */
    public static function literals(string $source): array
    {
        preg_match_all('/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $source, $matches);

        return array_map(fn (string $text) => stripcslashes($text), $matches[2]);
    }

    /** @return list<string> */
    private static function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (preg_match('/\.php$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
