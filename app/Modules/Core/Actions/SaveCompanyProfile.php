<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\CompanyProfile;
use App\Support\Exceptions\BusinessException;

/** Updates the company's legal and contact details (printed on invoices and reports). Never deletes the single profile row. */
final class SaveCompanyProfile
{
    private const TEXT = [
        'legal_name' => 150, 'trade_name' => 150, 'registration_no' => 60, 'tax_id' => 60, 'address_line1' => 150, 'address_line2' => 150,
        'city' => 100, 'country' => 100, 'phone' => 50, 'email' => 150, 'website' => 150,
    ];

    /** @param  array<string, mixed>  $data */
    public function __invoke(array $data): CompanyProfile
    {
        $profile = CompanyProfile::current() ?? new CompanyProfile(['legal_name' => 'My Company (Pvt) Ltd']);
        $values = [];
        foreach (self::TEXT as $field => $max) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $v = $data[$field] === null ? null : trim((string) $data[$field]);
            $v = $v === '' ? null : $v;
            if ($v !== null && mb_strlen($v) > $max) {
                throw new BusinessException('core.company_invalid', "{$field} is at most {$max} characters.", 422, [['field' => $field, 'message' => "At most {$max} characters."]]);
            }
            if ($field === 'legal_name' && $v === null) {
                throw new BusinessException('core.company_invalid', 'The legal name is required.', 422, [['field' => 'legal_name', 'message' => 'Required.']]);
            }
            if ($field === 'email' && $v !== null && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                throw new BusinessException('core.company_invalid', 'Enter a valid email address.', 422, [['field' => 'email', 'message' => 'Not a valid email address.']]);
            }
            $values[$field] = $v;
        }

        $profile->forceFill(['id' => CompanyProfile::ID])->fill($values)->save();

        return $profile->refresh();
    }
}
