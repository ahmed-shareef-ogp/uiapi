<?php

namespace Ogp\UiApi\Services;

use Illuminate\Support\Str;

class ComponentConfigService
{
    protected function defaultFilterTypeForDef(array $columnDef): string
    {
        $type = strtolower((string) ($columnDef['type'] ?? 'string'));

        if (in_array($type, ['date', 'datetime', 'timestamp'])) {
            return 'Date';
        }

        return 'Search';
    }

    protected function labelForColumn(array $columnDef, string $key, ?string $lang): string
    {
        $labels = $columnDef['label'] ?? [];
        if (is_array($labels) && $lang && isset($labels[$lang])) {
            return (string) $labels[$lang];
        }

        if (is_string($labels) && $labels !== '') {
            return $labels;
        }

        return Str::headline($key);
    }

    protected function columnSupportsLang(array $columnDef, ?string $lang): bool
    {
        $langs = $columnDef['lang'] ?? null;
        if ($lang === null || $lang === '') {
            return true;
        }

        if (is_array($langs)) {
            return in_array($lang, $langs, true);
        }

        return true;
    }

    public function buildFilters(array $schema, ?string $lang, ?array $allowedFilters = null): array
    {
        $filters = [];

        foreach (($schema['columns'] ?? []) as $key => $def) {
            if (! $this->columnSupportsLang((array) $def, $lang)) {
                continue;
            }

            $isFilterable = (bool) ($def['filterable'] ?? false);
            if (! $isFilterable && ! empty($allowedFilters) && ! in_array($key, $allowedFilters, true)) {
                // Skip if filters are constrained and this key is not allowed.
                continue;
            }

            $filters[] = [
                'type' => $this->defaultFilterTypeForDef((array) $def),
                'key' => $key,
                'label' => $this->labelForColumn((array) $def, $key, $lang),
            ];
        }

        return $filters;
    }

    public function buildTopLevelFilters(array $schema, ?string $lang, ?array $allowedFilters = null): array
    {
        return $this->buildFilters($schema, $lang, $allowedFilters);
    }

    protected function isOff($value): bool
    {
        return is_string($value) && strtolower($value) === 'off';
    }

    protected function loadJson(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    protected function resolveComponentConfigPath(string $name): ?string
    {
        $packagePath = base_path('packages/uiapi/resources/ComponentConfigs/'.$name.'.json');
        if (file_exists($packagePath)) {
            return $packagePath;
        }

        $appPath = base_path('app/Services/ComponentConfigs/'.$name.'.json');
        if (file_exists($appPath)) {
            return $appPath;
        }

        return null;
    }

    public function loadComponentConfig(string $name): ?array
    {
        $path = $this->resolveComponentConfigPath($name);

        return $path ? $this->loadJson($path) : null;
    }

    public function loadViewConfig(string $name): ?array
    {
        $appPath = base_path('app/Services/viewConfigs/'.$name.'.json');

        return $this->loadJson($appPath);
    }

    protected function singularize(string $key): string
    {
        return Str::singular($key);
    }

    protected function matchesOverride(array $item, string $needle): bool
    {
        $needleLower = strtolower($needle);

        if (isset($item['name']) && strtolower((string) $item['name']) === $needleLower) {
            return true;
        }

        if (isset($item['type']) && strtolower((string) $item['type']) === $needleLower) {
            return true;
        }

        if (isset($item['label'])) {
            $label = is_array($item['label']) ? ($item['label']['en'] ?? reset($item['label'])) : (string) $item['label'];
            if (strtolower((string) $label) === $needleLower) {
                return true;
            }
        }

        return false;
    }

    protected function applyOverridesToSection(array $section, array $overrides): array
    {
        foreach ($overrides as $key => $overrideList) {
            $overrideList = is_array($overrideList) ? $overrideList : [$overrideList];
            $overrideList = array_map(static fn ($v) => strtolower((string) $v), $overrideList);

            $resolvedKey = $section[$key] ?? null;
            if ($resolvedKey === null && isset($section[$this->singularize($key)])) {
                $resolvedKey = $section[$this->singularize($key)];
            }

            if (is_array($resolvedKey)) {
                $filtered = [];
                foreach ($resolvedKey as $item) {
                    foreach ($overrideList as $needle) {
                        if ($this->matchesOverride((array) $item, $needle)) {
                            $filtered[] = $item;
                            break;
                        }
                    }
                }
                $section[$key] = $filtered;
            }
        }

        return $section;
    }

    public function buildComponentSettingsForComponents(array $components, array $overrides = []): array
    {
        $settings = [];
        foreach ($components as $name => $value) {
            if (strtolower((string) $value) === 'off') {
                continue;
            }

            $config = $this->loadComponentConfig($name);
            if (! $config) {
                $settings['missing'][] = $name;

                continue;
            }

            $section = $config['settings'] ?? $config;
            if (isset($overrides[$name]) && is_array($overrides[$name])) {
                $section = $this->applyOverridesToSection($section, [$name => $overrides[$name]]);
            }

            $settings[$name] = $section;
        }

        return $settings;
    }
}
