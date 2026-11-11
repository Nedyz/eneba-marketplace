<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /orders (order history, D35). Only the page number: the page
 * size is fixed, and the list is always the signed-in user's own orders.
 */
class OrderIndexRequest extends FormRequest
{
    public const PER_PAGE = 10;

    /**
     * Far more pages than any buyer has. Without a ceiling, a page near
     * PHP_INT_MAX would overflow the OFFSET computation ((page - 1) * 10).
     */
    public const MAX_PAGE = 10_000;

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ];
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }
}
