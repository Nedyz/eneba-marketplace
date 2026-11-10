<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix (D32): the first seed linked two banners to /store?platform[]=steam,
 * which is the API's array syntax. The store page reads platform=steam (D29),
 * so those banners opened an unfiltered store.
 *
 * HomeSeeder is fixed too, but production was seeded once (D20) and is never
 * reseeded, so its rows are fixed here. Matching the exact old value makes this
 * a no-op on databases seeded after the fix and on banners edited by hand.
 */
return new class extends Migration
{
    private const LINKS = [
        '/store?platform[]=steam' => '/store?platform=steam',
        '/store?platform[]=nintendo' => '/store?platform=nintendo',
    ];

    public function up(): void
    {
        foreach (self::LINKS as $old => $new) {
            DB::table('banners')->where('link_url', $old)->update(['link_url' => $new]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: restoring a broken link would not help anyone.
    }
};
