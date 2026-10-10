<?php

namespace App\Modules\Core\Http\Controllers\Api;

use App\Modules\Core\Actions\Branches\CreateBranch;
use App\Modules\Core\Actions\Branches\DeleteBranch;
use App\Modules\Core\Actions\Branches\ListBranches;
use App\Modules\Core\Actions\Branches\UpdateBranch;
use App\Modules\Core\Http\Requests\StoreBranchRequest;
use App\Modules\Core\Http\Requests\UpdateBranchRequest;
use App\Modules\Core\Http\Resources\BranchResource;
use App\Modules\Core\Models\Branch;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BranchController extends Controller
{
    public function index(Request $request, ListBranches $list): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Branch::class);

        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'max:30'],
            'filter.is_active' => ['sometimes', 'in:true,false,1,0'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
        ]);

        $filter = $request->input('filter', []);
        if (isset($filter['is_active'])) {
            $filter['is_active'] = filter_var($filter['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        return BranchResource::collection($list($filter, (string) $request->query('sort', 'code'), (int) $request->query('per_page', 25)));
    }

    public function store(StoreBranchRequest $request, CreateBranch $create): JsonResponse
    {
        $branch = $create($request->validated());

        return (new BranchResource($branch))->response()->setStatusCode(201)
            ->header('Location', route('api.v1.branches.show', $branch));
    }

    public function show(Branch $branch): BranchResource
    {
        $this->authorize('view', $branch);

        return new BranchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch, UpdateBranch $update): BranchResource
    {
        $data = $request->safe()->except('version');

        return new BranchResource($update($branch, $data, (int) $request->validated('version')));
    }

    public function destroy(Branch $branch, DeleteBranch $delete): JsonResponse
    {
        $this->authorize('delete', $branch);
        $delete($branch);

        return response()->json(null, 204);
    }
}
