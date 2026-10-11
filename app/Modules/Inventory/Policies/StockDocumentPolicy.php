<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Inventory\Models\StockDocument;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Posting a document needs the permission of its kind (receive / issue / transfer / adjust); reading needs
 * `inventory.stock.view`; reversing needs `inventory.reverse` plus the permission of the document's kind.
 */
final class StockDocumentPolicy
{
    private const POST_PERMISSION = [
        StockDocument::RECEIPT => 'inventory.receive',
        StockDocument::ISSUE => 'inventory.issue',
        StockDocument::TRANSFER => 'inventory.transfer',
        StockDocument::ADJUSTMENT => 'inventory.adjust',
    ];

    public function viewAny(Authorizable $user): bool
    {
        return $user->can('inventory.stock.view');
    }

    public function view(Authorizable $user, StockDocument $document): bool
    {
        return $user->can('inventory.stock.view');
    }

    public function post(Authorizable $user, string $type): bool
    {
        return isset(self::POST_PERMISSION[$type]) && $user->can(self::POST_PERMISSION[$type]);
    }

    public function reverse(Authorizable $user, StockDocument $document): bool
    {
        return $user->can('inventory.reverse') && $this->post($user, $document->doc_type);
    }
}
