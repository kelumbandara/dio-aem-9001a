<?php

namespace App\Modules\Core\Contracts;

/**
 * Marker for Eloquent models that can carry attachments. Requirements on the implementing model:
 *
 *  - a unique `public_id` column (uuid) used by the API,
 *  - a morph-map alias registered with `Relation::morphMap()` (the alias is the API's `attachable_type`),
 *  - a Policy with `view` (list/download) and `attach` (upload/delete) abilities; attachments are only
 *    as visible as the thing they are attached to.
 */
interface HasAttachments {}
