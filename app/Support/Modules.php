<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Lets a feature plug in from its own files instead of editing shared ones.
 * Each config/modules/<name>.php can declare the keys documented in docs/MODULES.md.
 */
final class Modules
{
    /** Adds module permissions and plan features to the config. Runs after admin settings are applied. */
    public static function apply(): void
    {
        foreach (config('modules', []) as $module) {
            foreach ($module['permissions'] ?? [] as $role => $permissions) {
                $current = config("buildos.roles.{$role}.permissions", []);
                config(["buildos.roles.{$role}.permissions" => array_values(array_unique([...$current, ...$permissions]))]);
            }

            if (! isset($module['feature'])) {
                continue;
            }

            foreach ($module['plans'] ?? [] as $plan) {
                $current = config("buildos.plans.{$plan}.features", []);
                config(["buildos.plans.{$plan}.features" => array_values(array_unique([...$current, $module['feature']]))]);
            }
        }
    }

    /** Language files live in lang/modules/<name>/{hi,mr}.json. */
    public static function registerTranslations(): void
    {
        foreach (glob(lang_path('modules/*'), GLOB_ONLYDIR) ?: [] as $directory) {
            app('translator')->addJsonPath($directory);
        }
    }

    /**
     * Menu items for one part of the sidebar, limited to what the company's plan includes.
     *
     * @return list<array{label:string,route:string,active:string,can:?string,group:?string}>
     */
    public static function nav(string $place, Organization $organization): array
    {
        $items = [];

        foreach (config('modules', []) as $module) {
            if (isset($module['feature']) && ! $organization->allowsFeature($module['feature'])) {
                continue;
            }

            foreach ($module['nav'] ?? [] as $item) {
                if ($item['place'] === $place) {
                    $items[] = ['can' => null, 'group' => null, 'order' => 100, 'active' => $item['route'], ...$item];
                }
            }
        }

        usort($items, fn ($a, $b) => [$a['group'] ?? '', $a['order']] <=> [$b['group'] ?? '', $b['order']]);

        return $items;
    }

    /** @return array<string, string> */
    public static function icons(): array
    {
        $icons = [];

        foreach (config('modules', []) as $module) {
            $icons = [...$icons, ...($module['icons'] ?? [])];
        }

        return $icons;
    }
}
