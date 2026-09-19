<?php

namespace App\Http\Requests\Admin\AuditLogs;

use App\Enums\AuditAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAuditLogs');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user'      => ['nullable', 'integer', 'min:1'],
            'action'    => ['nullable', Rule::enum(AuditAction::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page'  => ['nullable'],
            'page'      => ['nullable'],
        ];
    }
}
