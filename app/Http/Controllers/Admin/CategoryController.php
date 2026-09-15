<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ActivityLogger;
use App\Services\Admin\CategoryTaxonomyService;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function __construct(private CategoryTaxonomyService $taxonomy) {}

    public function index(): View
    {
        $categories = Category::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->map(function (Category $category) {
                $category->setAttribute('site_count', $this->taxonomy->siteUsageCount($category->name));

                return $category;
            });

        $groups = $categories->pluck('group')->filter()->unique()->sort()->values();

        return view('admin.categories.index', compact('categories', 'groups'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'group' => 'required|string|max:80',
        ]);

        try {
            $category = $this->taxonomy->create($data['name'], $data['group']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', UserFacingError::message($e, 'Could not create that niche.'));
        }

        ActivityLogger::tryLog(
            'category.created',
            ($request->user()?->name ?? 'Admin').' created niche '.$category->name,
            null,
            ['name' => $category->name, 'group' => $category->group]
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('success', $category->name.' is available in the catalog picker.');
    }

    public function edit(Category $category): View
    {
        $groups = Category::query()
            ->orderBy('group')
            ->pluck('group')
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $siteCount = $this->taxonomy->siteUsageCount($category->name);

        return view('admin.categories.edit', compact('category', 'groups', 'siteCount'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'group' => 'required|string|max:80',
        ]);

        $from = $category->name;

        try {
            $category = $this->taxonomy->update($category, $data['name'], $data['group']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', UserFacingError::message($e, 'Could not update that niche.'));
        }

        ActivityLogger::tryLog(
            $from !== $category->name ? 'category.renamed' : 'category.updated',
            ($request->user()?->name ?? 'Admin').' updated niche '.$category->name,
            null,
            ['from' => $from, 'to' => $category->name, 'group' => $category->group]
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('success', $from !== $category->name
                ? 'Renamed to '.$category->name.' and updated matching sites.'
                : 'Niche updated.');
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $name = $category->name;

        try {
            $this->taxonomy->delete($category);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first() ?: $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not delete that niche.'));
        }

        ActivityLogger::tryLog(
            'category.deleted',
            ($request->user()?->name ?? 'Admin').' deleted niche '.$name,
            null,
            ['name' => $name]
        );

        return redirect()
            ->route('admin.categories.index')
            ->with('success', $name.' was removed from the picker.');
    }
}
