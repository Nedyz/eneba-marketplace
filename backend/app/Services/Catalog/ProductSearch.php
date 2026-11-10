<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Typo-tolerant product name search with pg_trgm (DECISIONS.md D28).
 *
 * Match: `name ILIKE '%term%'` (exact substring) OR `term <% name` (fuzzy:
 * word_similarity(term, name) >= the session's pg_trgm.word_similarity_threshold).
 * Both conditions are served by the GIN trigram index products_name_trgm_idx
 * (Postgres combines two bitmap index scans with BitmapOr).
 *
 * Why word_similarity and not similarity: similarity() divides by the trigrams of
 * the WHOLE name, so long titles score low even when they contain the exact word
 * typed ("zelda" vs "The Legend of Zelda: Tears of the Kingdom" = 0.18).
 * word_similarity() scores the best-matching part of the name instead (1.0 there).
 */
final class ProductSearch
{
    /**
     * Chosen on the real catalog: at 0.4, 30 of 30 test typos find their game and
     * every unrelated match scores below the intended one. pg_trgm's default 0.6
     * finds 21 of 30 (it misses "witcer", "pokemon", "balders gate"...).
     */
    public const WORD_SIMILARITY_THRESHOLD = '0.4';

    /**
     * Runs $callback with the lowered threshold, inside a transaction.
     *
     * The `<%` operator reads its threshold from a setting, not from the query:
     * only the operator form can use the index (writing
     * `word_similarity(term, name) >= 0.4` instead is a sequential scan, 13x slower
     * at 50k products). set_config(..., true) = SET LOCAL: the value lasts until the
     * transaction ends, so it never leaks into other queries on this connection.
     * The callback should run every query that needs it (page AND count).
     *
     * Without a search term nothing needs the setting, so no transaction is opened.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withThreshold(?string $term, Closure $callback): mixed
    {
        if ($term === null) {
            return $callback();
        }

        return DB::transaction(function () use ($callback) {
            DB::select("SELECT set_config('pg_trgm.word_similarity_threshold', ?, true)", [self::WORD_SIMILARITY_THRESHOLD]);

            return $callback();
        });
    }

    /**
     * Keeps products whose name matches $term. Needs withThreshold() around the
     * query; otherwise the fuzzy part runs at pg_trgm's default 0.6.
     *
     * @param  Builder<Product>  $query
     */
    public function whereMatches(Builder $query, string $term): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereRaw('products.name ILIKE ?', ['%'.self::escapeLike($term).'%'])
            // ?::text: PDO sends an untyped parameter; the cast lets Postgres pick
            // the text <% text operator (the one the trigram index supports).
            ->orWhereRaw('?::text <% products.name', [$term]));
    }

    /**
     * Relevance order:
     * 1. names starting with the term ("mario": Mario Kart before Super Mario Odyssey)
     * 2. word_similarity, highest first (the intended game above fuzzy noise)
     * 3. similarity, highest first (tie-breaker: prefers names closer in length to the term)
     * 4. name; ProductQuery always appends products.id last (stable pages, D24).
     *
     * @param  Builder<Product>  $query
     */
    public function orderByRelevance(Builder $query, string $term): void
    {
        $query
            ->orderByRaw('products.name ILIKE ? DESC', [self::escapeLike($term).'%'])
            ->orderByRaw('word_similarity(?::text, products.name) DESC', [$term])
            ->orderByRaw('similarity(?::text, products.name) DESC', [$term])
            ->orderBy('products.name');
    }

    /**
     * User input is literal text: without escaping, a search for "%" or "_" would
     * match every name. Backslash is Postgres's default LIKE escape character.
     */
    private static function escapeLike(string $term): string
    {
        return addcslashes($term, '\\%_');
    }
}
