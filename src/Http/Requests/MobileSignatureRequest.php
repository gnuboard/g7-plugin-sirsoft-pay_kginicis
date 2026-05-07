<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MobileSignatureRequest extends FormRequest
{
    /**
     * authorize
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

/**

 * rules

 *

 * @return array

 */

    public function rules(): array
    {
        return [
            'oid'       => ['required', 'string'],
            'price'     => ['required', 'integer', 'min:1'],
            'timestamp' => ['required', 'string'],
        ];
    }
}
