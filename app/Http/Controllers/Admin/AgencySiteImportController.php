<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgencySiteImport;
use Illuminate\Http\Request;

class AgencySiteImportController extends Controller
{
    public function index(Request $request)
    {
        $status = trim((string) $request->get('status', 'open'));
        $q = trim((string) $request->get('q', ''));

        $imports = AgencySiteImport::query()
            ->with(['publisher:id,name,email'])
            ->withCount(['sites', 'failures'])
            ->when($status === 'open', function ($query) {
                $query->whereIn('status', [
                    AgencySiteImport::STATUS_SUBMITTED,
                    AgencySiteImport::STATUS_PARTIAL,
                ]);
            })
            ->when($status !== '' && $status !== 'open' && $status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    if (ctype_digit($q)) {
                        $inner->orWhere('id', (int) $q);
                    }
                    $inner->orWhere('original_filename', 'like', "%{$q}%")
                        ->orWhereHas('publisher', function ($u) use ($q) {
                            $u->where('name', 'like', "%{$q}%")
                                ->orWhere('email', 'like', "%{$q}%");
                        });
                });
            })
            ->where('dry_run', false)
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $openCount = AgencySiteImport::query()
            ->where('dry_run', false)
            ->whereIn('status', [
                AgencySiteImport::STATUS_SUBMITTED,
                AgencySiteImport::STATUS_PARTIAL,
            ])
            ->count();

        return view('admin.agency-imports.index', compact('imports', 'status', 'q', 'openCount'));
    }

    public function show(int $id)
    {
        $import = AgencySiteImport::query()
            ->with([
                'publisher:id,name,email',
                'failures' => fn ($q) => $q->orderBy('row_number'),
                'sites' => fn ($q) => $q->latest('id'),
            ])
            ->findOrFail($id);

        return view('admin.agency-imports.show', compact('import'));
    }
}
