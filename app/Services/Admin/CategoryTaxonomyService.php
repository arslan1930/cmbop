<?php

namespace App\Services\Admin;

use App\Models\Category;
use App\Models\Site;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CategoryTaxonomyService
{
    public function create(string $name, string $group): Category
    {
        $name = $this->normalizedName($name);
        $group = $this->normalizedGroup($group);
        $this->assertNameAllowed($name);
        $this->assertUnique($name);

        return Category::query()->create([
            'name' => $name,
            'group' => $group,
        ]);
    }

    public function update(Category $category, string $name, string $group): Category
    {
        $name = $this->normalizedName($name);
        $group = $this->normalizedGroup($group);
        $this->assertNameAllowed($name, $category->id);
        $this->assertUnique($name, $category->id);

        $from = (string) $category->name;
        $category->fill([
            'name' => $name,
            'group' => $group,
        ])->save();

        if ($from !== $name) {
            $this->reassignSites($from, $name);
        }

        return $category->fresh();
    }

    public function delete(Category $category): void
    {
        $count = $this->siteUsageCount($category->name);
        if ($count > 0) {
            throw ValidationException::withMessages([
                'category' => 'This niche is used by '.$count.' site(s). Reassign them before deleting.',
            ]);
        }

        $category->delete();
    }

    public function siteUsageCount(string $name): int
    {
        return Site::query()
            ->where(function ($q) use ($name) {
                $q->where('category', $name);
                if ($this->sitesHaveCategoriesJson()) {
                    $q->orWhereJsonContains('categories', $name);
                }
            })
            ->count();
    }

    public function reassignSites(string $from, string $to): void
    {
        Site::query()->where('category', $from)->update(['category' => $to]);

        if (! $this->sitesHaveCategoriesJson()) {
            return;
        }

        Site::query()
            ->whereJsonContains('categories', $from)
            ->orderBy('id')
            ->each(function (Site $site) use ($from, $to) {
                $list = $site->getCategoriesArrayAttribute();
                $next = [];
                $seen = [];
                foreach ($list as $label) {
                    $label = trim((string) $label);
                    if ($label === $from) {
                        $label = $to;
                    }
                    if ($label === '' || isset($seen[strtolower($label)])) {
                        continue;
                    }
                    $seen[strtolower($label)] = true;
                    $next[] = $label;
                }
                $site->categories = $next;
                $site->save();
            });
    }

    private function normalizedName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    private function normalizedGroup(string $group): string
    {
        $group = trim(preg_replace('/\s+/', ' ', $group) ?? $group);

        return $group !== '' ? $group : 'Other';
    }

    private function assertNameAllowed(string $name, ?int $ignoreId = null): void
    {
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages([
                'name' => 'Niche name is required (max 80 characters).',
            ]);
        }

        if (str_contains($name, '|')) {
            throw ValidationException::withMessages([
                'name' => 'Niche names cannot contain “|” — that character splits catalog filters.',
            ]);
        }

        $current = $ignoreId
            ? Category::query()->whereKey($ignoreId)->value('name')
            : null;
        $commaOk = in_array($name, Category::NICHES_CONTAINING_COMMA, true)
            || ($current !== null && $current === $name);

        if (str_contains($name, ',') && ! $commaOk) {
            throw ValidationException::withMessages([
                'name' => 'New niches cannot contain commas — catalog URLs treat commas as separators.',
            ]);
        }
    }

    private function assertUnique(string $name, ?int $ignoreId = null): void
    {
        $query = Category::query()->where('name', $name);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A niche with this name already exists.',
            ]);
        }
    }

    private function sitesHaveCategoriesJson(): bool
    {
        try {
            return Schema::hasColumn('sites', 'categories');
        } catch (\Throwable) {
            return false;
        }
    }
}
