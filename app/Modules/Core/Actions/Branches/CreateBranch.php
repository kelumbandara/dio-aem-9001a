<?php

namespace App\Modules\Core\Actions\Branches;

use App\Modules\Core\Models\Branch;

final class CreateBranch
{
    /**
     * @param  array{code: string, name: string, is_head_office?: bool, address_line1?: ?string, address_line2?: ?string, city?: ?string, phone?: ?string, email?: ?string, is_active?: bool}  $data
     */
    public function __invoke(array $data): Branch
    {
        return Branch::query()->create($data)->refresh();
    }
}
