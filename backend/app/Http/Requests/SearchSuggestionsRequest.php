<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /search/suggestions. At least 2 characters: a 1-character term
 * matches almost every name and has no trigram the index could use (D28).
 * Leading/trailing spaces are trimmed by middleware before min:2 is checked.
 */
class SearchSuggestionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:'.ProductIndexRequest::MAX_QUERY_LENGTH],
        ];
    }

    public function term(): string
    {
        return (string) ProductIndexRequest::normalizeSearch($this->validated('q'));
    }
}
