▗ ▗   ▖ ▖  Claude Code v2.1.322
           Opus 5.5 · Claude Pro
 ▘▘   ▝▝   ~/Desktop/Eneba/eneba-marketplace

  Fable 5.2 is here for your longest and toughest coding tasks, and is included in your plan until November 16. It draws down usage faster than Opus 5.5. You also get a free Fable limit reset to redeem at clau.de/reset by November 16. Switch with /model.

❯ day 4, session 3: homepage blocks. read PLAN.md and DECISIONS.md first,       
  especially D4, and continue on the day4 branch. PR #2 is open. backend: GET   
  /home built from the home_blocks table, plus tests. frontend: the homepage    
  picks a component by block type and reuses ProductCard. nothing about the     
  blocks is hardcoded in the frontend. show me the plan before writing code.    
  when it's done, merge PR #2 once CI is green, then switch to main and pull.   

⏺ I'll start by reading the plan, the decisions, and the code this feature
  touches.

  Read 2 files, ran 2 shell commands (ctrl+o to expand)

⏺ Now the existing backend and frontend code around the homepage.

  Ran 2 shell commands (ctrl+o to expand)

⏺ Now the existing patterns I'd reuse: the store query, list resource,
  controller, and a test.

  Read 1 file, ran 1 shell command (ctrl+o to expand)

⏺ Now the frontend: router, current homepage, API client, ProductCard.

  Read 1 file, ran 4 shell commands (ctrl+o to expand)

⏺ I've read PLAN.md, DECISIONS.md (D3, D4, D21, D23, D24, D30, D31) and the
  code. Nothing for the homepage exists yet except the home_blocks and banners
  tables, their models and HomeSeeder (3 blocks, 3 banners). The seed has 105
  products, 39 products with paid sales in the last 30 days, and 93 discounted
  active offers.

  Plan: server-driven homepage (D4)

  A scope point first. You asked for GET /home. But D4 says each block component
  fetches its own endpoint, so the page shows nothing unless /home/banners,
  /home/best-sellers and /home/deals exist too. PLAN.md already lists all four.
  I'll build all four, each with its own service as D3 describes.

  Backend

  Endpoint: GET /home
  Service (app/Services/Home/): HomeLayout
  Rule: Active home_blocks rows ordered by position, then id. Returns {blocks: 
    [{id, type, title, endpoint}]}. The type is passed through as stored, and
  the
     frontend skips types it doesn't know.
  ────────────────────────────────────────
  Endpoint: GET /home/banners
  Service (app/Services/Home/): HomeBanners
  Rule: Active banners whose starts_at/ends_at window contains $now (a null
  bound
    means no limit), ordered by position, then id.
  ────────────────────────────────────────
  Endpoint: GET /home/best-sellers
  Service (app/Services/Home/): BestSellers
  Rule: Units sold (SUM(quantity)), from paid orders with paid_at in the last 30

    days, added up per product across all its offers. Ties are broken by product

    id. Limit 12.
  ────────────────────────────────────────
  Endpoint: GET /home/deals
  Service (app/Services/Home/): Deals
  Rule: Products whose card price (the from-price offer in
    ProductQuery::withFromPrice) has an original price, ordered by discount,
    largest first, then product id. Limit 12.

  - Contract for product_carousel: the endpoint returns {data: ProductSummary[]}
    with the same ProductListResource the store uses, so a carousel card is
    exactly a store card. Because of this, a future "New releases" block only
    needs a service, a route and a home_blocks row. The frontend doesn't change.
  - Why deals rank by the card's offer and not by any discounted offer: say a
    seller has a −60% offer that isn't the cheapest. The card would then show a
    different price and badge than the reason the product is in the Deals block.
    That's the same consistency rule as D30.
  - Controller: a thin HomeController with 4 actions, and one $now per request
    (the same pattern as ProductController).
  - Tests (feature tests on PostgreSQL), written so that removing each rule
    makes a test fail, as in earlier sessions:
    - layout: order, is_active, shape, empty table
    - banners: start and end window boundaries, inactive, order
    - best sellers: 30-day boundary, only paid (pending, failed and expired are
      ignored), quantities summed across offers of one product, ranking and
      tie-break, limit, constant query count
    - deals: only discounted card offers, a discounted but sold-out offer is not
      used, order, limit

  Frontend (src/features/home/)

  - api/home.ts: types HomeBlock, Banner, plus getHomeLayout() and
    getBlockContent(endpoint). The client.ts guard only allows relative paths,
    and the path is always appended after the API base, so a bad database row
    cannot make the browser call another host.
  - blockRegistry.ts: { banner: BannerBlock, product_carousel: 
    ProductCarouselBlock }, plus a pure function renderableBlocks(blocks) that
    drops unknown types. It's tested in Vitest.
  - HomePage.tsx: fetches /home (TanStack Query) and renders each known block.
    It replaces HomePlaceholder. The title, order and set of blocks all come
    from the API. The only text in the frontend is a visually hidden <h1>.
  - ProductCarouselBlock: the block's title as an <h2>, then a horizontal
    scroll-snap row of ProductCard (headingLevel="h3"). While loading it shows
    ProductCardSkeleton.
  - BannerBlock: one banner at a time with previous/next buttons and dots, no
    autoplay (accessibility). It uses a generated gradient background like the
    product covers (D21). A link_url that isn't an internal path (/… but not
    //…) is skipped, and that check is a tested pure function.
  - Failure behaviour: each block loads and fails on its own. A failed block
    shows a small "Couldn't load" with a Retry button, and an empty block is
    hidden. If /home itself fails, the page shows an error with Retry.

  Questions (my recommendation first)

  1. Best seller that is sold out right now: leave it out of the carousel
     (recommended), or show it with "Out of stock" like the store does (D24)?
     I'd leave it out because the homepage block is there to lead to a purchase.
     The store is a catalog, so showing sold-out items there makes sense.
  2. Carousel size: 12 per block?

  Verification and wrap-up

  - Browser check (Playwright) on the local seed, with these changes made by SQL
    and then restored:
    - reorder blocks, rename a title, deactivate a block
    - add an unknown block type (it should be skipped)
    - add a second product_carousel row pointing at /home/deals (shows "no
      frontend change")
    - expire a banner
    - mobile view
  - D32 in DECISIONS.md, update PLAN.md (service names), session 10 in
    AI_LOG.md.
  - Run Pint, php artisan test, lint, Vitest and build, then make small commits
    and push to day4.
  - When CI on PR #2 is green: gh pr merge 2 --merge (a merge commit, not
    squash), then git switch main && git pull.
  - I'll leave the two untracked ai-logs/ transcripts uncommitted, as last
    session did, for your end-of-day transcript commit.

  Shall I go ahead, and what are your answers to questions 1 and 2?

✻ Baked for 1m 25s · done 7:57 PM

❯ go ahead. 1) leave sold-out best sellers out. 2) 12 per block.                
                                                                                
  one problem: OrderHistorySeeder dates the orders relative to the seed time,   
  and production was seeded once on nov 9 (D20). reviewers might open the site  
  weeks from now, and by around dec 9 no seeded order will be inside the 30-day 
  window, so the best sellers block would end up empty and hidden. keep the     
  30-day ranking, but if it gives fewer than 12 products, fill the rest with    
  all-time best sellers. add a test where every paid order is older than 30     
  days, and write it up in D32.                                                 

⏺ Going ahead with: sold-out best sellers left out, 12 per block, and the
  all-time fill.

  How I'll build the fill: one aggregate per product over paid orders, with two
  sums. recent_units is units sold in the last 30 days, total_units is all-time
  units. Sorting by recent_units DESC, total_units DESC, products.id gives
  exactly "the 30-day ranking first, then all-time fills the rest". A product
  that sold recently always comes before one that didn't, so the fill never
  pushes a recent seller down. Inside the 30-day ranking, ties are broken by
  all-time units, then id. I'll record that in D32.

  First, the models and factories the tests will need:

  Ran 1 shell command (ctrl+o to expand)

⏺ Backend first. The four services:

⏺ Bash(mkdir -p
      /Users/bastys/Desktop/Eneba/eneba-marketplace/backend/app/Services/Home &&
      cd
      /Users/bastys/Desktop/Eneba/eneba-marketplace/backend/app/Services/Home
      &…)
  ⎿  Created backend/app/Services/Home/BestSellers.php (+67 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace App\Services\Home;                                   
       4 +                                                               
       5 +use App\Models\Product;                                        
       6 +use App\Services\Catalog\ProductQuery;                         
       7 +use Carbon\CarbonInterface;                                    
       8 +use Illuminate\Database\Eloquent\Collection;                   
       9 +use Illuminate\Support\Facades\DB;                             
      10 +                                                               
      11 +/**                                                            
      12 + * Best sellers block: products ranked by units sold in the las
         +t 30 days, with                                                
      13 + * the remaining places filled by all-time best sellers (D32). 
      14 + *                                                             
      15 + * Why the fill: the demo orders are dated relative to the seed
         + time, and                                                     
      16 + * production is seeded once (D20). About 30 days after that, n
         +o seeded order is                                              
      17 + * inside the window, and a pure 30-day ranking would return no
         +thing.                                                         
      18 + *                                                             
      19 + * One aggregate per product computes both numbers, and the sor
         +t does the rest:                                               
      20 + *   recent_units DESC  -> anything sold in the window comes fi
         +rst, by window sales                                           
      21 + *   total_units DESC   -> then all-time sales (also breaks tie
         +s inside the window)                                           
      22 + *   products.id        -> deterministic                       
      23 + * A product with recent sales therefore always ranks above one
         + without, so the                                               
      24 + * fill can only use places the 30-day ranking left empty.     
      25 + *                                                             
      26 + * Sold-out products are left out (Nedas's choice): this block 
         +is there to lead                                               
      27 + * to a purchase. The price is the store card's from price (Pro
         +ductQuery), so a                                               
      28 + * card here is exactly the card in the store.                 
      29 + */                                                            
      30 +class BestSellers                                              
      31 +{                                                              
      32 +    public const LIMIT = 12;                                   
      33 +                                                               
      34 +    public const WINDOW_DAYS = 30;                             
      35 +                                                               
      36 +    public function __construct(private ProductQuery $products)
         + {}                                                            
      37 +                                                               
      38 +    /** @return Collection<int, Product> */                    
      39 +    public function top(CarbonInterface $now): Collection      
      40 +    {                                                          
     … 27 more lines (ctrl+o to expand)
  ⎿  Created backend/app/Services/Home/Deals.php (+39 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace App\Services\Home;                                   
       4 +                                                               
       5 +use App\Models\Product;                                        
       6 +use App\Services\Catalog\ProductQuery;                         
       7 +use Carbon\CarbonInterface;                                    
       8 +use Illuminate\Database\Eloquent\Collection;                   
       9 +                                                               
      10 +/**                                                            
      11 + * Deals block: products whose CARD price is discounted, larges
         +t discount first.                                              
      12 + *                                                             
      13 + * Ranked by the from-price offer (cheapest sellable offer, Pro
         +ductQuery), not by                                             
      14 + * the most discounted offer of the product: otherwise a produc
         +t could be here                                                
      15 + * for a -60% offer while its card shows another seller's cheap
         +er, undiscounted                                               
      16 + * price. Same reasoning as D30: the card and what it links to 
         +must agree.                                                    
      17 + * A discounted offer that is sold out is not the from-price of
         +fer, so it never                                               
      18 + * puts a product in this block.                               
      19 + */                                                            
      20 +class Deals                                                    
      21 +{                                                              
      22 +    public const LIMIT = 12;                                   
      23 +                                                               
      24 +    public function __construct(private ProductQuery $products)
         + {}                                                            
      25 +                                                               
      26 +    /** @return Collection<int, Product> */                    
      27 +    public function top(CarbonInterface $now): Collection      
      28 +    {                                                          
      29 +        return $this->products->withFromPrice(Product::query(),
         + $now)                                                         
      30 +            ->whereNotNull('best_offer.original_price_cents') /
         +/ also excludes sold out (whole row null)                      
      31 +            // Price / original price, smallest first = largest
         + real discount first.                                          
      32 +            // ::numeric, otherwise integer division would roun
         +d every ratio to 0.                                            
      33 +            ->orderByRaw('best_offer.price_cents::numeric / bes
         +t_offer.original_price_cents ASC')                             
      34 +            ->orderBy('products.id')                           
      35 +            ->limit(self::LIMIT)                               
      36 +            ->with(['platform', 'region'])                     
      37 +            ->get();                                           
      38 +    }                                                          
      39 +}                                                              
  ⎿  Created backend/app/Services/Home/HomeBanners.php (+28 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace App\Services\Home;                                   
       4 +                                                               
       5 +use App\Models\Banner;                                         
       6 +use Carbon\CarbonInterface;                                    
       7 +use Illuminate\Database\Eloquent\Builder;                      
       8 +use Illuminate\Database\Eloquent\Collection;                   
       9 +                                                               
      10 +/**                                                            
      11 + * Content of the banner block: active banners whose schedule c
         +ontains $now.                                                  
      12 + * A null starts_at / ends_at means no limit on that side. The 
         +window is                                                      
      13 + * half-open [starts_at, ends_at): a banner ending at midnight 
         +is gone at midnight.                                           
      14 + */                                                            
      15 +class HomeBanners                                              
      16 +{                                                              
      17 +    /** @return Collection<int, Banner> */                     
      18 +    public function current(CarbonInterface $now): Collection  
      19 +    {                                                          
      20 +        return Banner::query()                                 
      21 +            ->where('is_active', true)                         
      22 +            ->where(fn (Builder $q) => $q->whereNull('starts_at
         +')->orWhere('starts_at', '<=', $now))                          
      23 +            ->where(fn (Builder $q) => $q->whereNull('ends_at')
         +->orWhere('ends_at', '>', $now))                               
      24 +            ->orderBy('position')                              
      25 +            ->orderBy('id')                                    
      26 +            ->get();                                           
      27 +    }                                                          
      28 +}                                                              
  ⎿  Created backend/app/Services/Home/HomeLayout.php (+24 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace App\Services\Home;                                   
       4 +                                                               
       5 +use App\Models\HomeBlock;                                      
       6 +use Illuminate\Database\Eloquent\Collection;                   
       7 +                                                               
       8 +/**                                                            
       9 + * The homepage layout (D4): which blocks exist, their order an
         +d titles all come                                              
      10 + * from `home_blocks`. The frontend only maps `type` to a compo
         +nent and fetches                                               
      11 + * `endpoint`, so reordering, renaming or hiding a block is a d
         +atabase change.                                                
      12 + */                                                            
      13 +class HomeLayout                                               
      14 +{                                                              
      15 +    /** @return Collection<int, HomeBlock> */                  
      16 +    public function blocks(): Collection                       
      17 +    {                                                          
      18 +        return HomeBlock::query()                              
      19 +            ->where('is_active', true)                         
      20 +            ->orderBy('position')                              
      21 +            ->orderBy('id') // two rows with the same position 
         +still have a fixed order                                       
      22 +            ->get();                                           
      23 +    }                                                          
      24 +}                                                              

⏺ Now the resources, controller and routes:

⏺ Bash(cd /Users/bastys/Desktop/Eneba/eneba-marketplace/backend && cat >
      app/Http/Resources/HomeBlockResource.php <<'EOF'
      <?php…)
  ⎿  == home
     3
       banner None None
     … +35 lines (ctrl+o to expand)
  ⎿  Created backend/app/Http/Controllers/Api/HomeController.php (+51 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace App\Http\Controllers\Api;                            
       4 +                                                               
       5 +use App\Http\Controllers\Controller;                           
       6 +use App\Http\Resources\BannerResource;                         
       7 +use App\Http\Resources\HomeBlockResource;                      
       8 +use App\Http\Resources\ProductListResource;                    
       9 +use App\Services\Home\BestSellers;                             
      10 +use App\Services\Home\Deals;                                   
      11 +use App\Services\Home\HomeBanners;                             
      12 +use App\Services\Home\HomeLayout;                              
      13 +use Illuminate\Http\JsonResponse;                              
      14 +use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
      15 +                                                               
      16 +/**                                                            
      17 + * Server-driven homepage (D4). GET /home is the layout; every 
         +other action is                                                
      18 + * the content of one block type, each from its own service and
         + data source (D3).                                             
      19 + *                                                             
      20 + * Contract per block type:                                    
      21 + * - banner:           endpoint returns { data: Banner[] }     
      22 + * - product_carousel: endpoint returns { data: ProductSummary[
         +] } (the store card)                                           
      23 + * So a new carousel ("New releases") needs a service, a route 
         +and a home_blocks                                              
      24 + * row, and no frontend change.                                
      25 + */                                                            
      26 +class HomeController extends Controller                        
      27 +{                                                              
      28 +    /** GET /api/home */                                       
      29 +    public function layout(HomeLayout $layout): JsonResponse   
      30 +    {                                                          
      31 +        return response()->json(['blocks' => HomeBlockResource:
         +:collection($layout->blocks())]);                              
      32 +    }                                                          
      33 +                                                               
      34 +    /** GET /api/home/banners */                               
      35 +    public function banners(HomeBanners $banners): AnonymousRes
         +ourceCollection                                                
      36 +    {                                                          
      37 +        return BannerResource::collection($banners->current(now
         +()));                                                          
      38 +    }                                                          
      39 +                                                               
      40 +    /** GET /api/home/best-sellers */                          
     … 11 more lines (ctrl+o to expand)
  ⎿  Created backend/app/Http/Resources/BannerResource.php (+28 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace App\Http\Resources;                                  
       4 +                                                               
       5 +use App\Models\Banner;                                         
       6 +use Illuminate\Http\Request;                                   
       7 +use Illuminate\Http\Resources\Json\JsonResource;               
       8 +                                                               
       9 +/**                                                            
      10 + * One banner of the banner block. Scheduling and status fields
         + stay internal:                                                
      11 + * the API only returns what is shown. `link_url` is a frontend
         + route.                                                        
      12 + *                                                             
      13 + * @mixin Banner                                               
      14 + */                                                            
      15 +class BannerResource extends JsonResource                      
      16 +{                                                              
      17 +    public function toArray(Request $request): array           
      18 +    {                                                          
      19 +        return [                                               
      20 +            'id' => $this->id,                                 
      21 +            'title' => $this->title,                           
      22 +            'subtitle' => $this->subtitle,                     
      23 +            'image_url' => $this->image_url,                   
      24 +            'cta_label' => $this->cta_label,                   
      25 +            'link_url' => $this->link_url,                     
      26 +        ];                                                     
      27 +    }                                                          
      28 +}                                                              
  ⎿  Created backend/app/Http/Resources/HomeBlockResource.php (+27 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace App\Http\Resources;                                  
       4 +                                                               
       5 +use App\Models\HomeBlock;                                      
       6 +use Illuminate\Http\Request;                                   
       7 +use Illuminate\Http\Resources\Json\JsonResource;               
       8 +                                                               
       9 +/**                                                            
      10 + * One homepage block (D4). `type` is passed through as stored:
         + the frontend                                                  
      11 + * skips types it does not know, so a new type never breaks an 
         +older frontend.                                                
      12 + * `endpoint` is a path relative to the API base.              
      13 + *                                                             
      14 + * @mixin HomeBlock                                            
      15 + */                                                            
      16 +class HomeBlockResource extends JsonResource                   
      17 +{                                                              
      18 +    public function toArray(Request $request): array           
      19 +    {                                                          
      20 +        return [                                               
      21 +            'id' => $this->id,                                 
      22 +            'type' => $this->type,                             
      23 +            'title' => $this->title,                           
      24 +            'endpoint' => $this->endpoint,                     
      25 +        ];                                                     
      26 +    }                                                          
      27 +}                                                              
  ⎿  Updated backend/routes/api.php (+8 -0)
      2  
      3  use App\Http\Controllers\Api\CartController;
      4  use App\Http\Controllers\Api\HealthController;
      5 +use App\Http\Controllers\Api\HomeController;                    
      6  use App\Http\Controllers\Api\ProductController;
      7  use App\Http\Controllers\Api\SearchController;
      8  use Illuminate\Http\Request;
     ...
      12  
      13  Route::get('/health', HealthController::class);
      14  
      15 +// Homepage (day 4): /home is the layout from home_blocks (D4);
         + the other three                                               
      16 +// are the content of the blocks the seeded layout points to (D
         +3, D32).                                                       
      17 +Route::get('/home', [HomeController::class, 'layout']);        
      18 +Route::get('/home/banners', [HomeController::class, 'banners'])
         +;                                                              
      19 +Route::get('/home/best-sellers', [HomeController::class, 'bestS
         +ellers']);                                                     
      20 +Route::get('/home/deals', [HomeController::class, 'deals']);   
      21 +                                                               
      22  // Catalog. /products/filters is registered before /products/{s
          lug} (day 4),
      23  // otherwise "filters" would be read as a product slug.
      24  Route::get('/products', [ProductController::class, 'index']);

⏺ All four endpoints pass on the seed; Split Fiction appears more than once in
  Best sellers because each platform is its own edition, and I'll note that in
  D32. Next I'm checking the layout and banner tests. · summary

⏺ Bash(mkdir -p
      /Users/bastys/Desktop/Eneba/eneba-marketplace/backend/tests/Feature/Home
      && cd
      /Users/bastys/Desktop/Eneba/eneba-marketplace/backend/tests/Feature/Home…)
  ⎿  Created backend/tests/Feature/Home/HomeBannersTest.php (+98 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace Tests\Feature\Home;                                  
       4 +                                                               
       5 +use App\Models\Banner;                                         
       6 +use Illuminate\Foundation\Testing\RefreshDatabase;             
       7 +use Illuminate\Support\Carbon;                                 
       8 +use Tests\TestCase;                                            
       9 +                                                               
      10 +/**                                                            
      11 + * GET /api/home/banners: active banners whose [starts_at, ends
         +_at) window                                                    
      12 + * contains now, by position.                                  
      13 + */                                                            
      14 +class HomeBannersTest extends TestCase                         
      15 +{                                                              
      16 +    use RefreshDatabase;                                       
      17 +                                                               
      18 +    protected function setUp(): void                           
      19 +    {                                                          
      20 +        parent::setUp();                                       
      21 +        Carbon::setTestNow('2026-11-10 12:00:00');             
      22 +    }                                                          
      23 +                                                               
      24 +    private function banner(array $attributes): Banner         
      25 +    {                                                          
      26 +        return Banner::create($attributes + [                  
      27 +            'title' => 'Banner', 'subtitle' => null, 'image_url
         +' => null, 'cta_label' => 'Go',                                
      28 +            'link_url' => '/store', 'position' => 1, 'is_active
         +' => true, 'starts_at' => null, 'ends_at' => null,             
      29 +        ]);                                                    
      30 +    }                                                          
      31 +                                                               
      32 +    private function titles(): array                           
      33 +    {                                                          
      34 +        return $this->getJson('/api/home/banners')->assertOk()-
         +>json('data.*.title');                                         
      35 +    }                                                          
      36 +                                                               
      37 +    public function test_returns_the_banner_shape(): void      
      38 +    {                                                          
      39 +        $banner = $this->banner([                              
      40 +            'title' => 'Steam keys for less', 'subtitle' => 'Sa
         +ve on PC games.', 'cta_label' => 'Browse Steam',               
     … 58 more lines (ctrl+o to expand)
  ⎿  Created backend/tests/Feature/Home/HomeLayoutTest.php (+76 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace Tests\Feature\Home;                                  
       4 +                                                               
       5 +use App\Models\HomeBlock;                                      
       6 +use Illuminate\Foundation\Testing\RefreshDatabase;             
       7 +use Tests\TestCase;                                            
       8 +                                                               
       9 +/**                                                            
      10 + * GET /api/home: the server-driven layout (D4). Which blocks e
         +xist, their order                                              
      11 + * and titles come from home_blocks only.                      
      12 + */                                                            
      13 +class HomeLayoutTest extends TestCase                          
      14 +{                                                              
      15 +    use RefreshDatabase;                                       
      16 +                                                               
      17 +    private function block(array $attributes): HomeBlock       
      18 +    {                                                          
      19 +        return HomeBlock::create($attributes + [               
      20 +            'type' => 'product_carousel', 'title' => 'Block', '
         +endpoint' => '/home/deals', 'position' => 1, 'is_active' => tru
         +e,                                                             
      21 +        ]);                                                    
      22 +    }                                                          
      23 +                                                               
      24 +    public function test_returns_the_block_shape(): void       
      25 +    {                                                          
      26 +        $block = $this->block(['type' => 'banner', 'title' => n
         +ull, 'endpoint' => '/home/banners']);                          
      27 +                                                               
      28 +        $this->getJson('/api/home')                            
      29 +            ->assertOk()                                       
      30 +            ->assertExactJson(['blocks' => [                   
      31 +                ['id' => $block->id, 'type' => 'banner', 'title
         +' => null, 'endpoint' => '/home/banners'],                     
      32 +            ]]);                                               
      33 +    }                                                          
      34 +                                                               
      35 +    public function test_blocks_are_ordered_by_position_not_by_
         +creation(): void                                               
      36 +    {                                                          
      37 +        $this->block(['title' => 'Third', 'position' => 3]);   
      38 +        $this->block(['title' => 'First', 'position' => 1]);   
      39 +        $this->block(['title' => 'Second', 'position' => 2]);  
      40 +                                                               
     … 36 more lines (ctrl+o to expand)

⏺ Now Best sellers, including the requested test where every paid order is older
  than 30 days, and Deals:

⏺ Bash(cat > BestSellersTest.php <<'EOF'
      <?php…)
  ⎿     PASS  Tests\Feature\Home\BestSellersTest
       ✓ returns store cards                                               
        0.55s
     … +67 lines (ctrl+o to expand)
  ⎿  Created backend/tests/Feature/Home/BestSellersTest.php (+228 -0)
        1 +<?php                                                         
        2 +                                                              
        3 +namespace Tests\Feature\Home;                                 
        4 +                                                              
        5 +use App\Models\Offer;                                         
        6 +use App\Models\Order;                                         
        7 +use App\Models\OrderItem;                                     
        8 +use App\Models\Product;                                       
        9 +use App\Models\User;                                          
       10 +use Carbon\CarbonInterface;                                   
       11 +use Illuminate\Foundation\Testing\RefreshDatabase;            
       12 +use Illuminate\Support\Carbon;                                
       13 +use Illuminate\Support\Facades\DB;                            
       14 +use Tests\TestCase;                                           
       15 +                                                              
       16 +/**                                                           
       17 + * GET /api/home/best-sellers (D32): units sold in the last 30
          + days, remaining                                              
       18 + * places filled by all-time units, sold-out products left out
          +, 12 at most.                                                 
       19 + *                                                            
       20 + * Products are created so that id order (the last tie-break) 
          +disagrees with the                                            
       21 + * expected order, so removing the rule under test fails the t
          +est.                                                          
       22 + */                                                           
       23 +class BestSellersTest extends TestCase                        
       24 +{                                                             
       25 +    use RefreshDatabase;                                      
       26 +                                                              
       27 +    private User $buyer;                                      
       28 +                                                              
       29 +    protected function setUp(): void                          
       30 +    {                                                         
       31 +        parent::setUp();                                      
       32 +        Carbon::setTestNow('2026-12-20 12:00:00');            
       33 +        $this->buyer = User::factory()->create();             
       34 +    }                                                         
       35 +                                                              
       36 +    /** A product with one in-stock offer. */                 
       37 +    private function product(string $slug): Product           
       38 +    {                                                         
       39 +        $product = Product::factory()->create(['slug' => $slug
          +]);                                                           
       40 +        Offer::factory()->for($product)->price(1999)->withKeys
          +(3)->create();                                                
     … 188 more lines (ctrl+o to expand)
  ⎿  Created backend/tests/Feature/Home/DealsTest.php (+106 -0)
        1 +<?php                                                         
        2 +                                                              
        3 +namespace Tests\Feature\Home;                                 
        4 +                                                              
        5 +use App\Models\Offer;                                         
        6 +use App\Models\Product;                                       
        7 +use Illuminate\Foundation\Testing\RefreshDatabase;            
        8 +use Tests\TestCase;                                           
        9 +                                                              
       10 +/**                                                           
       11 + * GET /api/home/deals (D32): products whose card price (the f
          +rom-price offer,                                              
       12 + * D23) is discounted, largest discount first, 12 at most.    
       13 + */                                                           
       14 +class DealsTest extends TestCase                              
       15 +{                                                             
       16 +    use RefreshDatabase;                                      
       17 +                                                              
       18 +    private function product(string $slug): Product           
       19 +    {                                                         
       20 +        return Product::factory()->create(['slug' => $slug]); 
       21 +    }                                                         
       22 +                                                              
       23 +    /** @return list<string> */                               
       24 +    private function slugs(): array                           
       25 +    {                                                         
       26 +        return $this->getJson('/api/home/deals')->assertOk()->
          +json('data.*.slug');                                          
       27 +    }                                                         
       28 +                                                              
       29 +    public function test_returns_store_cards_with_the_discount
          +(): void                                                      
       30 +    {                                                         
       31 +        Offer::factory()->for($this->product('hades'))->price(
          +1999, 2500)->withKeys()->create();                            
       32 +                                                              
       33 +        $this->getJson('/api/home/deals')                     
       34 +            ->assertJsonPath('data.0.slug', 'hades')          
       35 +            ->assertJsonPath('data.0.price', ['from_cents' => 
          +1999, 'original_cents' => 2500, 'discount_percent' => 20, 'cur
          +rency' => 'EUR']);                                            
       36 +    }                                                         
       37 +                                                              
       38 +    public function test_largest_discount_first(): void       
       39 +    {                                                         
       40 +        // Created in an order that matches neither the expect
          +ed order nor its reverse.                                     
     … 66 more lines (ctrl+o to expand)

⏺ All 33 pass. As in earlier sessions, I'll now break each rule on purpose and
  check that a test fails:

  Ran 1 shell command (ctrl+o to expand)

⏺ Two mutants survived and I'm rerunning both with corrected targets: the
  integer-division one hit a comment instead of the SQL, and removing the banner
  tie-break alone doesn't change the result, so I'll reverse it instead. 
  · summary

  Ran 1 shell command (ctrl+o to expand)

⏺ All 23 mutations are caught, and the files are back in their original state.
  Next: Pint, the full backend suite, then commit the backend.

  Ran 2 shell commands (ctrl+o to expand)

⏺ Now the frontend. First I'll check how existing pages handle loading and
  errors, plus the theme tokens, so the homepage matches them:

  Searched for 1 pattern, ran 1 shell command (ctrl+o to expand)

⏺ The banners link to /store?platform[]=steam, but the store reads
  platform=steam, so they would open an unfiltered store. I'm checking the
  platform slugs next. · summary

  Ran 1 shell command (ctrl+o to expand)

⏺ The slugs are correct, so only the parameter name is wrong. I'll fix the
  seeder and add a data migration to correct the two links in already-seeded
  databases, since production won't pick up a seeder change alone and will get
  the fix on the next deploy. · summary

⏺ Bash(cd /Users/bastys/Desktop/Eneba/eneba-marketplace/backend && sed -i ''
      "s#/store?platform\[\]=steam#/store?platform=steam#;
      s#/store?platform\[\]=nintendo#/store…)  ⎿  diff --git a/backend/database/migrations/2026_11_09_200010_create_bann
     ers_table.php b/backend/database/migrations/2026_11_09_200010_create_b
     anners_table.php
     … +41 lines (ctrl+o to expand)
  ⎿  Updated
     backend/database/migrations/2026_11_09_200010_create_banners_table.php
     (+1 -1)
      18              $table->string('subtitle')->nullable();
      19              $table->string('image_url')->nullable(); // null = 
          frontend draws a background
      20              $table->string('cta_label');
      21 -            $table->string('link_url');  // internal frontend r
         -oute, e.g. /store?platform[]=steam                             
      21 +            $table->string('link_url');  // internal frontend r
         +oute, e.g. /store?platform=steam                               
      22              $table->integer('position');
      23              $table->boolean('is_active')->default(true);
      24              $table->timestamp('starts_at')->nullable();
  ⎿  Created backend/database/migrations/2026_11_10_120001_fix_banner_store
     _links.php (+33 -0)
       1 +<?php                                                          
       2 +                                                               
       3 +use Illuminate\Database\Migrations\Migration;                  
       4 +use Illuminate\Support\Facades\DB;                             
       5 +                                                               
       6 +/**                                                            
       7 + * Data fix (D32): the first seed linked two banners to /store?
         +platform[]=steam,                                              
       8 + * which is the API's array syntax. The store page reads platfo
         +rm=steam (D29),                                                
       9 + * so those banners opened an unfiltered store.                
      10 + *                                                             
      11 + * HomeSeeder is fixed too, but production was seeded once (D20
         +) and is never                                                 
      12 + * reseeded, so its rows are fixed here. Matching the exact old
         + value makes this                                              
      13 + * a no-op on databases seeded after the fix and on banners edi
         +ted by hand.                                                   
      14 + */                                                            
      15 +return new class extends Migration                             
      16 +{                                                              
      17 +    private const LINKS = [                                    
      18 +        '/store?platform[]=steam' => '/store?platform=steam',  
      19 +        '/store?platform[]=nintendo' => '/store?platform=ninten
         +do',                                                           
      20 +    ];                                                         
      21 +                                                               
      22 +    public function up(): void                                 
      23 +    {                                                          
      24 +        foreach (self::LINKS as $old => $new) {                
      25 +            DB::table('banners')->where('link_url', $old)->upda
         +te(['link_url' => $new]);                                      
      26 +        }                                                      
      27 +    }                                                          
      28 +                                                               
      29 +    public function down(): void                               
      30 +    {                                                          
      31 +        // Nothing to undo: restoring a broken link would not h
         +elp anyone.                                                    
      32 +    }                                                          
      33 +};                                                             
  ⎿  Updated backend/database/seeders/HomeSeeder.php (+4 -3)
      22              ['type' => 'product_carousel', 'title' => 'Deals', 
          'endpoint' => '/home/deals', 'position' => 3, 'is_active' => tr
          ue],
      23          ]));
      24  
      25 -        // Links are frontend routes into the store (search liv
         -es in the store, D6).                                          
      25 +        // Links are frontend routes into the store (search liv
         +es in the store, D6),                                          
      26 +        // in the store's own URL format (D29): platform=steam,
         + not the API's platform[]=steam.                               
      27          DB::table('banners')->insert(array_map(fn ($b) => $b + 
          $stamps + [
      28              'image_url' => null, 'is_active' => true, 'starts_a
          t' => null, 'ends_at' => null,
      29          ], [
      30              ['title' => 'Steam keys for less', 'subtitle' => 'C
          ompare offers from trusted sellers and save on PC games.',
      30 -                'cta_label' => 'Browse Steam', 'link_url' => '/
         -store?platform[]=steam', 'position' => 1],                     
      31 +                'cta_label' => 'Browse Steam', 'link_url' => '/
         +store?platform=steam', 'position' => 1],                       
      32              ['title' => 'Play together', 'subtitle' => 'Split F
          iction, It Takes Two and more co-op adventures.',
      33                  'cta_label' => 'Find co-op games', 'link_url' =
          > '/store?q=split+fiction', 'position' => 2],
      34              ['title' => 'Nintendo favourites', 'subtitle' => 'Z
          elda, Mario Kart and Animal Crossing keys for Switch.',
      34 -                'cta_label' => 'Shop Nintendo', 'link_url' => '
         -/store?platform[]=nintendo', 'position' => 3],                 
      35 +                'cta_label' => 'Shop Nintendo', 'link_url' => '
         +/store?platform=nintendo', 'position' => 3],                   
      36          ]));
      37      }
      38  }

⏺ I'll add a test for the data migration, and check whether DemoSeedTest is the
  right place for a guard on the seeded links:

  Searched for 1 pattern, ran 1 shell command (ctrl+o to expand)

⏺ I'm adding two seed-level tests to DemoSeedTest, one checking that the real
  seed fills every block and Best sellers stays full after 90 days, and one
  checking the banner links, plus a data migration test. · summary

⏺ Bash(python3 - <<'EOF'
      p='tests/Feature/DemoSeedTest.php'…)
  ⎿    Tests:    14 passed (34 assertions)
       Duration: 4.24s
     … +9 lines (ctrl+o to expand)
  ⎿  Updated backend/tests/Feature/DemoSeedTest.php (+24 -0)
       96          $this->assertGreaterThan(0, (clone $paid)->where('paid
           _at', '<', now()->subDays(30))->count());
       97      }
       98  
       99 +    public function test_every_homepage_block_is_full_now_and_
          +long_after_the_seed(): void                                   
      100 +    {                                                         
      101 +        $this->getJson('/api/home')->assertJsonCount(3, 'block
          +s');                                                          
      102 +        $this->getJson('/api/home/banners')->assertJsonCount(3
          +, 'data');                                                    
      103 +        $this->getJson('/api/home/deals')->assertJsonCount(12,
          + 'data');                                                     
      104 +        $this->getJson('/api/home/best-sellers')->assertJsonCo
          +unt(12, 'data');                                              
      105 +                                                              
      106 +        // Production is seeded once (D20). 90 days later no s
          +eeded order is in                                             
      107 +        // the 30-day window, and the all-time fill keeps the 
          +block full (D32).                                             
      108 +        $this->travel(90)->days();                            
      109 +        $this->assertSame(0, DB::table('orders')->where('paid_
          +at', '>=', now()->subDays(30))->count());                     
      110 +        $this->getJson('/api/home/best-sellers')->assertJsonCo
          +unt(12, 'data');                                              
      111 +    }                                                         
      112 +                                                              
      113 +    public function test_banner_links_use_the_store_url_format
          +(): void                                                      
      114 +    {                                                         
      115 +        // The store reads platform=steam (D29); the API's pla
          +tform[]=steam would                                           
      116 +        // open an unfiltered store (D32).                    
      117 +        foreach (DB::table('banners')->pluck('link_url') as $l
          +ink) {                                                        
      118 +            $this->assertStringStartsWith('/', $link);        
      119 +            $this->assertStringNotContainsString('[]', $link);
      120 +        }                                                     
      121 +    }                                                         
      122 +                                                              
      123      public function test_demo_user_can_sign_in_with_the_docume
           nted_password(): void
      124      {
      125          $user = User::where('email', OrderHistorySeeder::DEMO_
           EMAIL)->firstOrFail();
  ⎿  Created backend/tests/Feature/Home/BannerLinkFixMigrationTest.php (+45
     -0)
       1 +<?php                                                          
       2 +                                                               
       3 +namespace Tests\Feature\Home;                                  
       4 +                                                               
       5 +use App\Models\Banner;                                         
       6 +use Illuminate\Foundation\Testing\RefreshDatabase;             
       7 +use Tests\TestCase;                                            
       8 +                                                               
       9 +/**                                                            
      10 + * The data migration that fixes banner links in databases seed
         +ed before D32                                                  
      11 + * (production). Runs the migration's up() again on rows in the
         + old format.                                                   
      12 + */                                                            
      13 +class BannerLinkFixMigrationTest extends TestCase              
      14 +{                                                              
      15 +    use RefreshDatabase;                                       
      16 +                                                               
      17 +    private function banner(string $link): Banner              
      18 +    {                                                          
      19 +        return Banner::create([                                
      20 +            'title' => 'Banner', 'cta_label' => 'Go', 'link_url
         +' => $link, 'position' => 1,                                   
      21 +        ]);                                                    
      22 +    }                                                          
      23 +                                                               
      24 +    private function runFix(): void                            
      25 +    {                                                          
      26 +        $migration = require database_path('migrations/2026_11_
         +10_120001_fix_banner_store_links.php');                        
      27 +        $migration->up();                                      
      28 +    }                                                          
      29 +                                                               
      30 +    public function test_rewrites_the_old_seeded_links_and_noth
         +ing_else(): void                                               
      31 +    {                                                          
      32 +        $steam = $this->banner('/store?platform[]=steam');     
      33 +        $nintendo = $this->banner('/store?platform[]=nintendo')
         +;                                                              
      34 +        $search = $this->banner('/store?q=split+fiction');     
      35 +        $editedByHand = $this->banner('/store?platform[]=steam&
         +sort=price_asc');                                              
      36 +                                                               
      37 +        $this->runFix();                                       
      38 +        $this->runFix(); // running twice changes nothing more 
      39 +                                                               
      40 +        $this->assertSame('/store?platform=steam', $steam->fres
         +h()->link_url);                                                
     … 5 more lines (ctrl+o to expand)

⏺ Both new tests pass and both mutations are caught; I'm committing the change
  now. · summary

  Ran 1 shell command (ctrl+o to expand)

⏺ Backend is finished, so I'm moving to the frontend. For the block registry
  I'll use Object.hasOwn, since a plain lookup would match inherited names like
  "constructor" and React would try to render them. · summary

⏺ Bash(cd /Users/bastys/Desktop/Eneba/eneba-marketplace/frontend && cat >
      src/api/home.ts <<'EOF'
      import { apiGet } from './client'…)
  ⎿   Test Files  1 passed (1)
           Tests  16 passed (16)
        Start at  20:05:37
     … +2 lines (ctrl+o to expand)
  ⎿  Created frontend/src/api/home.ts (+46 -0)
       1 +import { apiGet } from './client'                              
       2 +                                                               
       3 +/**                                                            
       4 + * Server-driven homepage (D4). GET /home says which blocks to 
         +show, in which                                                 
       5 + * order and with which titles; each block then fetches its own
         + `endpoint`.                                                   
       6 + * Mirrors backend HomeBlockResource.                          
       7 + */                                                            
       8 +export interface HomeBlock {                                   
       9 +  id: number                                                   
      10 +  /** Unknown types are skipped by the homepage, never an error
         +. */                                                           
      11 +  type: string                                                 
      12 +  title: string | null                                         
      13 +  /** Path relative to the API base, e.g. "/home/deals". */    
      14 +  endpoint: string                                             
      15 +}                                                              
      16 +                                                               
      17 +/** Mirrors backend BannerResource. `link_url` is a frontend ro
         +ute. */                                                        
      18 +export interface Banner {                                      
      19 +  id: number                                                   
      20 +  title: string                                                
      21 +  subtitle: string | null                                      
      22 +  image_url: string | null                                     
      23 +  cta_label: string                                            
      24 +  link_url: string                                             
      25 +}                                                              
      26 +                                                               
      27 +export function getHomeLayout(signal?: AbortSignal): Promise<Ho
         +meBlock[]> {                                                   
      28 +  return apiGet<{ blocks: HomeBlock[] }>('/home', { signal }).t
         +hen((response) => response.blocks)                             
      29 +}                                                              
      30 +                                                               
      31 +/**                                                            
      32 + * Content of one block. The endpoint comes from the database, 
         +so it is only                                                  
      33 + * ever requested through apiGet: that rejects anything not sta
         +rting with "/",                                                
      34 + * and appends the path to the API base URL, so even "//other.h
         +ost" stays on the                                              
      35 + * API host. The element type is the block type's contract (see
         + the backend's                                                 
      36 + * HomeController): banner -> Banner, product_carousel -> Produ
         +ctSummary.                                                     
      37 + */                                                            
      38 +export function getBlockContent<T>(endpoint: string, signal?: A
         +bortSignal): Promise<T[]> {                                    
      39 +  return apiGet<{ data: T[] }>(endpoint, { signal }).then((resp
         +onse) => response.data)                                        
      40 +}                                                              
     … 6 more lines (ctrl+o to expand)
  ⎿  Created frontend/src/features/home/homeBlocks.test.ts (+52 -0)
       1 +import { describe, expect, it } from 'vitest'                  
       2 +import type { HomeBlock } from '../../api/home'                
       3 +import { isInternalPath, renderableBlocks } from './homeBlocks'
       4 +                                                               
       5 +const block = (id: number, type: string): HomeBlock => ({ id, t
         +ype, title: null, endpoint: `/home/${id}` })                   
       6 +const registry = { banner: 'BannerBlock', product_carousel: 'Pr
         +oductCarouselBlock' }                                          
       7 +                                                               
       8 +describe('renderableBlocks', () => {                           
       9 +  it('keeps known blocks in API order, with their component', (
         +) => {                                                         
      10 +    const blocks = [block(3, 'product_carousel'), block(1, 'ban
         +ner'), block(2, 'product_carousel')]                           
      11 +                                                               
      12 +    expect(renderableBlocks(blocks, registry)).toEqual([       
      13 +      { block: blocks[0], Component: 'ProductCarouselBlock' }, 
      14 +      { block: blocks[1], Component: 'BannerBlock' },          
      15 +      { block: blocks[2], Component: 'ProductCarouselBlock' }, 
      16 +    ])                                                         
      17 +  })                                                           
      18 +                                                               
      19 +  it('skips unknown types instead of failing', () => {         
      20 +    const result = renderableBlocks([block(1, 'video_hero'), bl
         +ock(2, 'banner'), block(3, '')], registry)                     
      21 +                                                               
      22 +    expect(result.map((r) => r.block.id)).toEqual([2])         
      23 +  })                                                           
      24 +                                                               
      25 +  it('does not treat inherited object properties as block types
         +', () => {                                                     
      26 +    const blocks = ['constructor', 'toString', 'hasOwnProperty'
         +, '__proto__'].map((type, i) => block(i, type))                
      27 +                                                               
      28 +    expect(renderableBlocks(blocks, registry)).toEqual([])     
      29 +  })                                                           
      30 +                                                               
      31 +  it('returns nothing for an empty layout', () => {            
      32 +    expect(renderableBlocks([], registry)).toEqual([])         
      33 +  })                                                           
      34 +})                                                             
      35 +                                                               
      36 +describe('isInternalPath', () => {                             
      37 +  it.each(['/store', '/store?platform=steam', '/store?q=split+f
         +iction', '/product/elden-ring', '/'])('allows %s', (link) => { 
      38 +    expect(isInternalPath(link)).toBe(true)                    
      39 +  })                                                           
      40 +                                                               
     … 12 more lines (ctrl+o to expand)
  ⎿  Created frontend/src/features/home/homeBlocks.ts (+29 -0)
       1 +import type { HomeBlock } from '../../api/home'                
       2 +                                                               
       3 +/**                                                            
       4 + * Pure rules of the server-driven homepage (D4, D32), kept apa
         +rt from the                                                    
       5 + * components so Vitest can test them without rendering.       
       6 + */                                                            
       7 +                                                               
       8 +/**                                                            
       9 + * Blocks the frontend knows how to render, in API order, each 
         +with its                                                       
      10 + * component. Unknown types are dropped, so a block type added 
         +on the backend                                                 
      11 + * before the frontend knows it is skipped instead of crashing 
         +the page.                                                      
      12 + *                                                             
      13 + * Object.hasOwn and not `registry[type]`: a plain lookup would
         + find inherited                                                
      14 + * properties, so a type "constructor" or "toString" would retu
         +rn                                                             
      15 + * Object.prototype's function and React would try to render it
         +.                                                              
      16 + */                                                            
      17 +export function renderableBlocks<C>(blocks: HomeBlock[], regist
         +ry: Record<string, C>): { block: HomeBlock; Component: C }[] { 
      18 +  return blocks.flatMap((block) => (Object.hasOwn(registry, blo
         +ck.type) ? [{ block, Component: registry[block.type] }] : [])) 
      19 +}                                                              
      20 +                                                               
      21 +/**                                                            
      22 + * Banner links come from the database and are rendered as in-a
         +pp links, so                                                   
      23 + * only same-site paths are allowed: "/store?..." yes; "https:/
         +/...", "//host"                                                
      24 + * and "/\host" (which browsers also read as another host) no. 
         +A banner with any                                              
      25 + * other link is not shown.                                    
      26 + */                                                            
      27 +export function isInternalPath(link: string): boolean {        
      28 +  return link.startsWith('/') && !link.startsWith('//') && !lin
         +k.startsWith('/\\')                                            
      29 +}                                                              

⏺ The pure rules and their 16 tests pass. Now the components: a shared error
  state, the two block types, the registry, and the page.

⏺ Bash(cd
      /Users/bastys/Desktop/Eneba/eneba-marketplace/frontend/src/features/home
      && cat > BlockLoadError.tsx <<'EOF'
      /**…)
  ⎿  5:import { HomePage } from '../features/home/HomePage'
     21:      { index: true, element: <HomePage /> },
     … +13 lines (ctrl+o to expand)
  ⎿  Created frontend/src/features/home/BannerBlock.tsx (+115 -0)
        1 +import { useQuery } from '@tanstack/react-query'              
        2 +import { useState } from 'react'                              
        3 +import { Link } from 'react-router'                           
        4 +import { getBlockContent, homeQueryKeys, type Banner, type Hom
          +eBlock } from '../../api/home'                                
        5 +import { BlockLoadError } from './BlockLoadError'             
        6 +import { isInternalPath } from './homeBlocks'                 
        7 +                                                              
        8 +/**                                                           
        9 + * Background per slide when a banner has no image (D21: the f
          +rontend draws it).                                            
       10 + * Styling only, chosen by position; the content all comes fro
          +m the API.                                                    
       11 + * White text on each, contrast checked against the lighter st
          +op (>= 4.5:1).                                                
       12 + */                                                           
       13 +const GRADIENTS = [                                           
       14 +  'from-[#351183] to-[#7a2bd6]',                              
       15 +  'from-[#0b3d5c] to-[#17668c]',                              
       16 +  'from-[#5c0b3d] to-[#a81c6b]',                              
       17 +]                                                             
       18 +                                                              
       19 +/**                                                           
       20 + * Block type "banner": the banners from the block's endpoint,
          + one at a time,                                               
       21 + * with previous/next buttons and dots. No autoplay: moving co
          +ntent the user                                                
       22 + * did not start is hard to read and to click (WCAG 2.2.2), an
          +d the page has no                                             
       23 + * pause control to offer.                                    
       24 + */                                                           
       25 +export function BannerBlock({ block }: { block: HomeBlock }) {
       26 +  const content = useQuery({                                  
       27 +    queryKey: homeQueryKeys.block(block.endpoint),            
       28 +    queryFn: ({ signal }) => getBlockContent<Banner>(block.end
          +point, signal),                                               
       29 +  })                                                          
       30 +  const [current, setCurrent] = useState(0)                   
       31 +                                                              
       32 +  if (content.isPending) {                                    
       33 +    return <div aria-hidden className="h-72 animate-pulse bg-w
          +hite/5 md:h-80" />                                            
       34 +  }                                                           
       35 +  if (content.isError) {                                      
       36 +    return <BlockLoadError what={block.title ?? 'the banners'}
          + onRetry={() => content.refetch()} />                         
       37 +  }                                                           
       38 +                                                              
       39 +  // A banner whose link would leave the site is not shown (D3
          +2).                                                           
       40 +  const banners = content.data.filter((banner) => isInternalPa
          +th(banner.link_url))                                          
     … 75 more lines (ctrl+o to expand)
  ⎿  Created frontend/src/features/home/BlockLoadError.tsx (+14 -0)
       1 +/**                                                            
       2 + * A block that failed to load says so in its own place, with a
         + retry: one                                                    
       3 + * failing block (e.g. its service is down) never takes the hom
         +epage with it.                                                 
       4 + */                                                            
       5 +export function BlockLoadError({ what, onRetry }: { what: strin
         +g; onRetry: () => void }) {                                    
       6 +  return (                                                     
       7 +    <div role="alert" className="flex flex-wrap items-center ga
         +p-4 bg-bg-deep px-5 py-4">                                     
       8 +      <p className="text-text-soft">Could not load {what}.</p> 
       9 +      <button type="button" onClick={onRetry} className="font-e
         +xtrabold underline">                                           
      10 +        Try again                                              
      11 +      </button>                                                
      12 +    </div>                                                     
      13 +  )                                                            
      14 +}                                                              
  ⎿  Created frontend/src/features/home/HomePage.tsx (+61 -0)
       1 +import { useQuery } from '@tanstack/react-query'               
       2 +import { Link } from 'react-router'                            
       3 +import { getHomeLayout, homeQueryKeys } from '../../api/home'  
       4 +import { PageContainer } from '../../components/layout/PageCont
         +ainer'                                                         
       5 +import { blockRegistry } from './blockRegistry'                
       6 +import { renderableBlocks } from './homeBlocks'                
       7 +                                                               
       8 +/**                                                            
       9 + * "/": the server-driven homepage (D4). Fetches the layout, th
         +en renders each                                                
      10 + * block the frontend knows with its component; each block fetc
         +hes its own                                                    
      11 + * content, so blocks load (and fail) independently, in paralle
         +l.                                                             
      12 + */                                                            
      13 +export function HomePage() {                                   
      14 +  const layout = useQuery({                                    
      15 +    queryKey: homeQueryKeys.layout(),                          
      16 +    queryFn: ({ signal }) => getHomeLayout(signal),            
      17 +  })                                                           
      18 +                                                               
      19 +  return (                                                     
      20 +    <PageContainer className="flex flex-col gap-12 py-8">      
      21 +      <title>Intersheba · Game keys from trusted sellers</title
         +>                                                              
      22 +      {/* Every visible heading comes from the API; the page st
         +ill needs an h1. */}                                           
      23 +      <h1 className="sr-only">Intersheba</h1>                  
      24 +                                                               
      25 +      {layout.isPending && <div aria-hidden className="h-72 ani
         +mate-pulse bg-white/5 md:h-80" />}                             
      26 +                                                               
      27 +      {layout.isError && (                                     
      28 +        <div role="alert" className="flex flex-col items-start 
         +gap-4 py-12">                                                  
      29 +          <p className="text-xl font-bold">Could not load the h
         +omepage.</p>                                                   
      30 +          <button                                              
      31 +            type="button"                                      
      32 +            onClick={() => layout.refetch()}                   
      33 +            className="bg-accent px-5 py-3 text-button font-ext
         +rabold text-black hover:bg-accent-hover"                       
      34 +          >                                                    
      35 +            Try again                                          
      36 +          </button>                                            
      37 +        </div>                                                 
      38 +      )}                                                       
      39 +                                                               
      40 +      {layout.isSuccess && <Blocks blocks={layout.data} />}    
     … 21 more lines (ctrl+o to expand)
  ⎿  Deleted frontend/src/features/home/HomePlaceholder.tsx (+0 -20)
       1 -import { Link } from 'react-router'                            
       2 -import { PageContainer } from '../../components/layout/PageCont
         -ainer'                                                         
       3 -                                                               
       4 -/**                                                            
       5 - * Temporary "/" until the server-driven homepage exists (day 4
         -, DECISIONS.md D4).                                            
       6 - */                                                            
       7 -export function HomePlaceholder() {                            
       8 -  return (                                                     
       9 -    <PageContainer className="py-12">                          
      10 -      <h1 className="mb-4 text-3xl font-bold">Intersheba</h1>  
      11 -      <p className="mb-6 max-w-xl text-text-soft">             
      12 -        A demo game key marketplace built as an Eneba internshi
         -p assignment. The store, search and checkout are being         
      13 -        built this week.                                       
      14 -      </p>                                                     
      15 -      <Link to="/health" className="inline-block bg-accent px-5
         - py-3 text-button font-extrabold text-black hover:bg-accent-hov
         -er">                                                           
      16 -        Check API status                                       
      17 -      </Link>                                                  
      18 -    </PageContainer>                                           
      19 -  )                                                            
      20 -}                                                              
  ⎿  Created frontend/src/features/home/ProductCarouselBlock.tsx (+78 -0)
       1 +import { useQuery } from '@tanstack/react-query'               
       2 +import { useRef } from 'react'                                 
       3 +import { getBlockContent, homeQueryKeys, type HomeBlock } from 
         +'../../api/home'                                               
       4 +import type { ProductSummary } from '../../api/products'       
       5 +import { ProductCard, ProductCardSkeleton } from '../../compone
         +nts/ui/ProductCard'                                            
       6 +import { ArrowButton } from './BannerBlock'                    
       7 +import { BlockLoadError } from './BlockLoadError'              
       8 +                                                               
       9 +/** Card width per breakpoint: about 2.3 cards on a phone (the 
         +cut-off card says "swipe"), 6 on desktop. */                   
      10 +const ITEM_CLASSES = 'w-[42%] shrink-0 snap-start sm:w-[30%] md
         +:w-[calc((100%-3*1rem)/4)] lg:w-[calc((100%-5*1rem)/6)]'       
      11 +                                                               
      12 +/**                                                            
      13 + * Block type "product_carousel": a titled row of store cards. 
         +Which products                                                 
      14 + * (best sellers, deals, anything a future endpoint returns) is
         + decided by the                                                
      15 + * block's endpoint; the title comes from home_blocks. This com
         +ponent knows                                                   
      16 + * neither, so a new carousel needs no frontend change (D4).   
      17 + */                                                            
      18 +export function ProductCarouselBlock({ block }: { block: HomeBl
         +ock }) {                                                       
      19 +  const content = useQuery({                                   
      20 +    queryKey: homeQueryKeys.block(block.endpoint),             
      21 +    queryFn: ({ signal }) => getBlockContent<ProductSummary>(bl
         +ock.endpoint, signal),                                         
      22 +  })                                                           
      23 +  const scroller = useRef<HTMLUListElement>(null)              
      24 +                                                               
      25 +  // An empty carousel is hidden rather than shown as an empty 
         +row.                                                           
      26 +  if (content.isSuccess && content.data.length === 0) {        
      27 +    return null                                                
      28 +  }                                                            
      29 +                                                               
      30 +  const headingId = `home-block-${block.id}`                   
      31 +  const scroll = (direction: 1 | -1) => {                      
      32 +    const list = scroller.current                              
      33 +    if (!list) return                                          
      34 +    const reduceMotion = window.matchMedia('(prefers-reduced-mo
         +tion: reduce)').matches                                        
      35 +    list.scrollBy({ left: direction * list.clientWidth, behavio
         +r: reduceMotion ? 'auto' : 'smooth' })                         
      36 +  }                                                            
      37 +                                                               
      38 +  return (                                                     
      39 +    <section aria-labelledby={block.title ? headingId : undefin
         +ed} className="flex flex-col gap-4">                           
      40 +      <div className="flex items-end justify-between gap-4">   
     … 38 more lines (ctrl+o to expand)
  ⎿  … 2 more files changed

⏺ All 121 Vitest tests, lint and the build pass. Before checking in the browser,
  I'm moving ArrowButton into components/ui and typing the parameter as
  HomeBlock[]. · summary

⏺ Bash(cd /Users/bastys/Desktop/Eneba/eneba-marketplace/frontend/src && python3
      - <<'EOF'
      import re…)
  ⎿  [warn] src/components/ui/ArrowButton.tsx
     [warn] src/api/home.ts
     [warn] Code style issues found in 9 files. Run Prettier with --write t
     … +2 lines (ctrl+o to expand)
  ⎿  Created frontend/src/components/ui/ArrowButton.tsx (+13 -0)
       1 +/** Square previous/next button, used by the homepage carousels
         +. */                                                           
       2 +export function ArrowButton({ direction, onClick }: { direction
         +: 'previous' | 'next'; onClick: () => void }) {                
       3 +  return (                                                     
       4 +    <button                                                    
       5 +      type="button"                                            
       6 +      onClick={onClick}                                        
       7 +      aria-label={direction === 'previous' ? 'Previous' : 'Next
         +'}                                                             
       8 +      className="flex size-10 items-center justify-center bg-bl
         +ack/50 text-xl font-extrabold text-white hover:bg-black/75"    
       9 +    >                                                          
      10 +      <span aria-hidden>{direction === 'previous' ? '‹' : '›'}<
         +/span>                                                         
      11 +    </button>                                                  
      12 +  )                                                            
      13 +}                                                              
  ⎿  Updated frontend/src/features/home/BannerBlock.tsx (+1 -13)
      1  import { useQuery } from '@tanstack/react-query'
      2  import { useState } from 'react'
      3  import { Link } from 'react-router'
      4 +import { ArrowButton } from '../../components/ui/ArrowButton'   
      5  import { getBlockContent, homeQueryKeys, type Banner, type HomeB
         lock } from '../../api/home'
      6  import { BlockLoadError } from './BlockLoadError'
      7  import { isInternalPath } from './homeBlocks'
     ...
      101      </section>
      102    )
      103  }
      103 -                                                              
      104 -export function ArrowButton({ direction, onClick }: { directio
          -n: 'previous' | 'next'; onClick: () => void }) {              
      105 -  return (                                                    
      106 -    <button                                                   
      107 -      type="button"                                           
      108 -      onClick={onClick}                                       
      109 -      aria-label={direction === 'previous' ? 'Previous' : 'Nex
          -t'}                                                           
      110 -      className="flex size-10 items-center justify-center bg-b
          -lack/50 text-xl font-extrabold text-white hover:bg-black/75"  
      111 -    >                                                         
      112 -      <span aria-hidden>{direction === 'previous' ? '‹' : '›'}
          -</span>                                                       
      113 -    </button>                                                 
      114 -  )                                                           
      115 -}                                                             
  ⎿  Updated frontend/src/features/home/HomePage.tsx (+2 -2)
      1  import { useQuery } from '@tanstack/react-query'
      2  import { Link } from 'react-router'
      3 -import { getHomeLayout, homeQueryKeys } from '../../api/home'   
      3 +import { getHomeLayout, homeQueryKeys, type HomeBlock } from '..
        +/../api/home'                                                   
      4  import { PageContainer } from '../../components/layout/PageConta
         iner'
      5  import { blockRegistry } from './blockRegistry'
      6  import { renderableBlocks } from './homeBlocks'
     ...
      42    )
      43  }
      44  
      45 -function Blocks({ blocks }: { blocks: Parameters<typeof rendera
         -bleBlocks>[0] }) {                                             
      45 +function Blocks({ blocks }: { blocks: HomeBlock[] }) {         
      46    const renderable = renderableBlocks(blocks, blockRegistry)
      47  
      48    if (renderable.length === 0) {
  ⎿  Updated frontend/src/features/home/ProductCarouselBlock.tsx (+1 -1)
      2  import { useRef } from 'react'
      3  import { getBlockContent, homeQueryKeys, type HomeBlock } from '
         ../../api/home'
      4  import type { ProductSummary } from '../../api/products'
      5 +import { ArrowButton } from '../../components/ui/ArrowButton'   
      6  import { ProductCard, ProductCardSkeleton } from '../../componen
         ts/ui/ProductCard'
      6 -import { ArrowButton } from './BannerBlock'                     
      7  import { BlockLoadError } from './BlockLoadError'
      8  
      9  /** Card width per breakpoint: about 2.3 cards on a phone (the c
         ut-off card says "swipe"), 6 on desktop. */

⏺ Prettier flags files, but I need to know whether the project uses Prettier at
  all before acting on that:

  Ran 1 shell command (ctrl+o to expand)

⏺ Prettier isn't part of this project (no config, no script, and the existing
  files "fail" its defaults too), so I'll ignore it. oxlint printed nothing, so
  I'll check its exit code directly:

  Ran 1 shell command (ctrl+o to expand)

⏺ Lint is clean. Now the real-browser check. I'll start both servers:

  Read 1 file, called plugin:playwright:playwright 5 times, ran 1 shell command (ctrl+o to expand)

⏺ The homepage renders fine on desktop with no console errors, but the
  screenshot is 2402px wide in a 1440px viewport, so something overflows
  sideways. I'm tracking that down now, and then I'll run the database checks,
  mobile, docs, CI and the merge. · summary

  Called plugin:playwright:playwright (ctrl+o to expand)

⏺ The carousel cards past the edge are clipped by their scroll container, which
  is expected. But documentElement.scrollWidth reads 2402 against body's 1440,
  so I'm checking whether the window can really scroll sideways. · summary

  Called plugin:playwright:playwright (ctrl+o to expand)

⏺ The page scrolls 962px sideways because the sr-only spans in Price are
  absolutely positioned and escape the carousel's clipping; making the scroller
  relative should fix it. · summary

⏺ Bash(cd /Users/bastys/Desktop/Eneba/eneba-marketplace/frontend && python3 -
      <<'EOF'
      p='src/features/home/ProductCarouselBlock.tsx'; s=open(p).read()…)
  ⎿  Updated frontend/src/features/home/ProductCarouselBlock.tsx (+4 -1)
      58        ) : (
      59          <ul
      60            ref={scroller}
      61 -          className="flex snap-x snap-mandatory gap-4 overflow-
         -x-auto pb-2 [scrollbar-width:thin]"                            
      61 +          // relative: the cards' sr-only labels are position:a
         +bsolute, and an                                                
      62 +          // overflow container only clips absolute descendants
         + it positions.                                                 
      63 +          // Without it, off-screen cards' labels widened the w
         +hole page.                                                     
      64 +          className="relative flex snap-x snap-mandatory gap-4 
         +overflow-x-auto pb-2 [scrollbar-width:thin]"                   
      65          >
      66            {content.isPending
      67              ? Array.from({ length: 6 }, (_, i) => (

  Called plugin:playwright:playwright 2 times (ctrl+o to expand)

⏺ The page no longer scrolls sideways, and both carousels now hold 12 cards
  each. I'm checking the banner controls, carousel scrolling, and whether the
  banner CTA opens a filtered store. · summary

  Called plugin:playwright:playwright (ctrl+o to expand)

⏺ Two Next clicks in a row only advanced one slide, because go(index + 1) reads
  a stale index before React re-renders. I'm switching it to a functional state
  update to fix that. · summary

⏺ Bash(python3 - <<'EOF'
      p='src/features/home/BannerBlock.tsx'; s=open(p).read()…)
  ⎿  lint ok
  ⎿  Updated frontend/src/features/home/BannerBlock.tsx (+7 -4)
      46    // The list can shrink on a refetch (a banner ended): stay in
           range.
      47    const index = current % banners.length
      48    const banner = banners[index]
      49 -  const go = (to: number) => setCurrent((to + banners.length) %
         - banners.length)                                               
      49 +  const count = banners.length                                 
      50 +  // Functional update: two quick clicks both count, even befor
         +e React re-renders                                             
      51 +  // (with `index + 1` both would read the same stale index).  
      52 +  const step = (delta: 1 | -1) => setCurrent((c) => (((c % coun
         +t) + delta) % count + count) % count)                          
      53  
      54    return (
      55      <section aria-roledescription="carousel" aria-label={block.
          title ?? 'Featured'} className="relative">
     ...
       90                <button
       91                  key={b.id}
       92                  type="button"
       90 -                onClick={() => go(i)}                         
       93 +                onClick={() => setCurrent(i)}                 
       94                  aria-label={`Show banner ${i + 1}: ${b.title}`
           }
       95                  aria-current={i === index}
       96                  className={`size-3 border-2 border-white ${i =
           == index ? 'bg-white' : 'bg-transparent hover:bg-white/50'}`}
       97                />
       98              ))}
       99            </div>
       97 -          <ArrowButton direction="previous" onClick={() => go(
          -index - 1)} />                                                
       98 -          <ArrowButton direction="next" onClick={() => go(inde
          -x + 1)} />                                                    
      100 +          <ArrowButton direction="previous" onClick={() => ste
          +p(-1)} />                                                     
      101 +          <ArrowButton direction="next" onClick={() => step(1)
          +} />                                                          
      102          </div>
      103        )}
      104      </section>

  Called plugin:playwright:playwright 2 times (ctrl+o to expand)

⏺ Stepping now works in every case: two quick clicks, wrap-around both ways, and
  the dots. Next, the "Browse Steam" CTA should open a store filtered to Steam:

  Called plugin:playwright:playwright 3 times (ctrl+o to expand)

⏺ The click happened before the banner had loaded, so we stayed on /. I'll wait
  for the link to appear, click it, then read only the platform line of each
  card:
  Called plugin:playwright:playwright (ctrl+o to expand)

⏺ The CTA link opens the Steam store page as expected, so the fix works end to
  end. Next I'll run the D4 checks by changing the layout in the database only,
  after backing up both tables. · summary

  Called plugin:playwright:playwright 2 times, ran 1 shell command (ctrl+o to expand)

⏺ Round 1 behaves as intended: deals moved first, video_hero is skipped, the new
  "More deals" carousel renders, and the banner block shows only "Nintendo
  favourites". Next I'll test round 2, where a block's endpoint fails and then a
  layout has no active blocks. · summary

  Called plugin:playwright:playwright 2 times, ran 1 shell command (ctrl+o to expand)

⏺ The failing block shows its own error message while the other blocks render
  normally, and the two console 404s are expected. I'm checking the last
  database case, which has no active blocks, next. · summary

  Called plugin:playwright:playwright 2 times, ran 1 shell command (ctrl+o to expand)

⏺ The empty layout shows the fallback with a link to the store. Now I'll restore
  both tables exactly from the backups and confirm they match:

  Ran 1 shell command (ctrl+o to expand)

⏺ Restored, with no row differences either way. Now mobile (390px):

  Read 1 file, called plugin:playwright:playwright 4 times (ctrl+o to expand)

⏺ Mobile layout checks out: no sideways scroll, the cut-off card hints at
  swiping, and the banner arrows clear the CTA. I'm closing the browser,
  stopping the servers, and running all CI checks on the final code next. 
  · summary

  Called plugin:playwright:playwright, ran 1 shell command (ctrl+o to expand)

⏺ Frontend checks pass (lint, 121 Vitest tests, build). Same mutation check for
  the two pure frontend rules:

  Ran 1 shell command (ctrl+o to expand)

⏺ All 5 are caught. Committing the frontend:

  Ran 1 shell command (ctrl+o to expand)

⏺ Before writing D32, I'm checking whether the benchmark database still exists,
  since Deals and Best sellers compute the from price for each candidate
  product. · summary

  Ran 2 shell commands (ctrl+o to expand)

⏺ The benchmark dataset has one paid order with an item for each of the 200k
  offers, so both queries get exercised. I'm timing the two new services against
  it next, using the same bootstrap as run.php. · summary

  Ran 2 shell commands (ctrl+o to expand)

⏺ At 50k products, Best sellers takes 138 ms and Deals 71 ms, the same cost
  pattern as the store's price_asc sort, and negligible on the real 105-product
  catalog. I'm logging it in D32 with a cache as the fix, and dropping the
  throwaway database next. · summary

  Ran 2 shell commands (ctrl+o to expand)

⏺ Bash(python3 - <<'EOF'
      p='docs/PLAN.md'; s=open(p).read()…)
  ⎿  187:## Session 8 — 10 November 2026: product page (day 4, session 1)
     208:## Session 9 — 10 November 2026: cart (day 4, session 2)
  ⎿  Updated docs/DECISIONS.md (+16 -0)
      224  - **State module instead of a React context** (changed from PL
           AN.md during the session): the cart is one module-level store 
           read with `useSyncExternalStore` (like a singleton service wit
           h a change event). Each change writes localStorage once, synch
           ronously, rather than in an effect that StrictMode runs twice;
            another tab's change arrives through the `storage` event as o
           ne more update (verified: adding in one tab updated the other 
           tab's open cart page). The per-visit price notes live in the s
           ame store, so detecting a change and recording the new price a
           s seen happen in one step, without a `setState` inside an effe
           ct (oxlint `set-state-in-effect`).
      225  - **Tests**: 28 backend feature tests (each status and its bou
           ndary, the sellable rule, expiry, ignored client prices, reque
           st order, no reservation, constant query count, 13 validation 
           cases); 12 rules broken one at a time to confirm a test fails.
            40 Vitest tests for the storage and price-note rules; 7 of th
           em mutation-checked.
      226  - **Gave up**: the cart does not follow the user across device
           s (D8); the header count comes from localStorage and can inclu
           de lines that are no longer buyable until the cart page is ope
           ned.
      227 +                                                              
      228 +## D32. Homepage blocks: content rules, the all-time fill, saf
          +e links (10 Nov)                                              
      229 +- **Endpoints**: `GET /home` (layout, D4) and one endpoint per
          + block, each with its own service in `Services/Home` (D3): `Ho
          +meLayout`, `HomeBanners`, `BestSellers`, `Deals`. Carousels re
          +turn the store's `ProductListResource`, so a homepage card is 
          +the store card, with the same from price (D23). That is the `p
          +roduct_carousel` contract: a new carousel ("New releases") is 
          +a service, a route and a `home_blocks` row, with no frontend c
          +hange. Verified in the browser by adding a second carousel row
          + in SQL.                                                      
      230 +- **Layout**: active rows by `position`, then `id`. The type i
          +s passed through unvalidated; the frontend skips unknown types
          + (`Object.hasOwn` lookup, so a type such as `constructor` is u
          +nknown too, not `Object.prototype`'s function).               
      231 +- **Banners**: active, inside a half-open `[starts_at, ends_at
          +)` window (null = no limit on that side), by position then id.
          + Shown one at a time with previous/next buttons and dots, **no
          + autoplay** (WCAG 2.2.2: moving content needs a pause control,
          + and a slider that never moves on its own needs none).        
      232 +- **Best sellers** = units (not orders) from **paid** orders, 
          +summed per product over all its offers, **12** at most, **sold
          +-out products left out** (Nedas's choice: the block is there t
          +o lead to a purchase; the store keeps them, D24). A product is
          + an edition (D5), so one game can appear once per platform.   
      233 +  - **All-time fill** (Nedas's requirement): demo orders are d
          +ated relative to the seed time and production is seeded once (
          +D20), so from about 9 December no seeded order would be inside
          + the 30-day window and a pure 30-day ranking would be empty (a
          +nd the block hidden) when reviewers open the site. One aggrega
          +te computes both `recent_units` (30 days, `SUM ... FILTER`) an
          +d `total_units`, sorted `recent_units DESC, total_units DESC, 
          +products.id`. Anything sold in the window ranks first, by wind
          +ow sales; the remaining places go to all-time best sellers; th
          +e fill can never push a recent seller down. Ties inside the wi
          +ndow are broken by all-time units, then id. The window starts 
          +exactly 30 days before now, inclusive.                        
      234 +  - **Tests**: every paid order older than 30 days still gives
          + a full, all-time-ranked block; `DemoSeedTest` travels 90 days
          + past the real seed and still gets 12.                        
      235 +- **Deals** = products whose **card price** (the from-price of
          +fer) has an original price, by real discount (price ÷ original
          +, smallest first; `::numeric`, or integer division makes every
          + ratio 0), then id, 12 at most. Not "the most discounted offer
          + of the product": that offer may not be the card's, so a produ
          +ct would sit in Deals for −60% while its card shows another se
          +ller's cheaper, undiscounted price (same rule as D30). A disco
          +unted offer that is sold out is not the card's offer, so it ne
          +ver counts.                                                   
      236 +- **Bug found in the day 2 seed, fixed**: two banners linked t
          +o `/store?platform[]=steam` (the API's array syntax); the stor
          +e reads `platform=steam` (D29), so they opened an unfiltered s
          +tore. `HomeSeeder` is fixed, and a data migration rewrites exa
          +ctly those two old values in already-seeded databases (product
          +ion, D20); a banner edited by hand is left alone. `DemoSeedTes
          +t` checks that no seeded link uses `[]`.                      
      237 +- **Link safety**: endpoints only ever go through `apiGet` (re
          +lative path appended to the API base, D4). Banner links are re
          +ndered as in-app links only if they are same-site paths: `/…` 
          +but not `//host` or `/\host` (both read as another host by bro
          +wsers); otherwise the banner is skipped.                      
      238 +- **Failure behaviour**: each block loads and fails on its own
          + ("Could not load Deals. Try again"); an empty block is hidden
          +; no renderable block shows "Nothing featured right now" with 
          +a link to the store; a failed layout shows a page-level retry.
          + All checked in the browser by changing rows in SQL (reorder, 
          +rename, unknown type, extra carousel, expired banner, external
          + link, 404 endpoint, all blocks inactive), restored afterwards
          +.                                                             
      239 +- **Two frontend bugs found in the browser**: the cards' `sr-o
          +nly` price labels are `position: absolute`, and an overflow co
          +ntainer only clips absolute descendants when it is their conta
          +ining block, so off-screen cards widened the whole page (the w
          +indow scrolled 962 px sideways). The scroller is now `relative
          +`. And two quick clicks on Next moved one slide (`index + 1` r
          +ead a stale index); stepping now uses a functional state updat
          +e.                                                            
      240 +- **Tests**: 34 backend feature tests (layout, banners, best s
          +ellers, deals, link migration) plus 2 in `DemoSeedTest`; 25 ru
          +les broken one at a time to confirm a test fails. 16 Vitest te
          +sts for the registry filter and the link rule, 5 mutation-chec
          +ked.                                                          
      241 +- **Cost, measured** (benchmark database, 50k products / 200k 
          +offers / 1M keys, best of 5, SQL time): layout 0.5 ms, banners
          + 0.4 ms, **best sellers 138 ms, deals 71 ms**: both compute th
          +e from price of every candidate before taking 12, like the sto
          +re's `price_asc` sort (D23). Negligible at the real catalog's 
          +105 products, so not optimized. At scale, a short cache (about
          + a minute) would fix it; the product page and checkout re-chec
          +k stock anyway (D30, D31).                                    
      242 +- **Gave up**: no autoplay; no per-block cache; the backend do
          +es not validate block types or banner links on write (there is
          + no admin UI; the frontend guards on read).                   
  ⎿  Updated docs/PLAN.md (+5 -4)
      28  │   │   ├── Models/
      29  │   │   ├── Services/
      30  │   │   │   ├── Catalog/        # ProductQuery, SearchService
      31 -│   │   │   ├── Home/           # HomeLayoutService, BannerServ
         -ice, BestSellersService, DealsService                          
      31 +│   │   │   ├── Home/           # HomeLayout, HomeBanners, Best
         +Sellers, Deals                                                 
      32  │   │   │   └── Checkout/       # CheckoutService (reserve, pay
          , expire)
      33  │   │   └── Payments/           # PaymentGateway interface, Fak
          ePaymentGateway
      34  │   ├── database/{migrations,seeders,factories}/
     ...
      84  - Unknown block types are skipped, so an old frontend never cra
          shes on a new block type.
      85  - Which blocks appear, their order and their titles are all dec
          ided in the database. A new block ("New releases") with an exis
          ting type needs only a `home_blocks` row and a backend service,
           with no frontend change.
      86  - Endpoints are stored as paths relative to the API base, and t
          he frontend only requests them under the API base URL, so a bad
           database row cannot make the browser call another host.
      87 +- Contract per type: a `banner` endpoint returns `{data: Banner
         +[]}`, a `product_carousel` endpoint returns `{data: ProductSumm
         +ary[]}` (the store card, `ProductListResource`). Banner links a
         +re frontend routes in the store's URL format (`/store?platform=
         +steam`); a banner whose link is not a same-site path is not sho
         +wn. Details: D32.                                              
      88  
      89  ## Checkout and key assignment
      90  
     ...
      117  | POST | `/auth/logout` | yes | Revoke token |
      118  | GET | `/auth/me` | yes | Current user |
      119  | GET | `/home` | | Homepage layout (list of blocks) |
      119 -| GET | `/home/banners` | | Banner block content |            
      120 -| GET | `/home/best-sellers` | | Top products by units sold in
          - the last 30 days |                                           
      121 -| GET | `/home/deals` | | Top offers by discount % |          
      120 +| GET | `/home/banners` | | Active banners inside their `[star
          +ts_at, ends_at)` window, by position |                        
      121 +| GET | `/home/best-sellers` | | 12 in-stock products by units
          + sold in the last 30 days, remaining places filled by all-time
          + units (D32) |                                                
      122 +| GET | `/home/deals` | | 12 products whose card price (from-p
          +rice offer) is discounted, largest discount first (D32) |     
      123  | GET | `/products` | | Store and search: `q, platform[], regi
           on[], price_min, price_max` (cents), `sort` (`newest`, `price_
           asc`, `price_desc`, `name`, `relevance`; default `relevance` w
           hen `q` is set, see D28), `page, per_page` (24, max 60). From 
           price: see D23. |
      124  | GET | `/products/filters` | | Available platforms, regions, 
           price range |
      125  | GET | `/products/{slug}` | | Product with its active offers:
            in stock first, then price, then offer id (D30) |

⏺ Those on-disk edits were mine from the last command, and I'm correcting my
  earlier mutation-check count from 25 to 23 before adding the session log. 
  · summary

⏺ Bash(sed -i '' 's/plus 2 in `DemoSeedTest`; 25 rules broken one at a time/plus
      2 in `DemoSeedTest`; 23 rules broken one at a time/' docs/DECISIONS.md &&
      grep -c "23…)
  ⎿  1
     pint ok
       Tests:    154 passed (441 assertions)
  ⎿  Updated docs/AI_LOG.md (+22 -0)
      225  - `/checkout` does not exist yet, so the enabled Checkout butt
           on leads to the 404 page until day 5.
      226  - In development, each cart page load sends one quote that is 
           aborted and repeated (StrictMode's double mount); production s
           ends one.
      227  - The header count can include lines that are no longer buyabl
           e until the cart page is opened.
      228 +                                                              
      229 +## Session 10 — 10 November 2026: homepage blocks (day 4, sess
          +ion 3)                                                        
      230 +                                                              
      231 +**Done**                                                      
      232 +- Plan agreed first. Scope: `GET /home` plus the three block e
          +ndpoints its seeded layout points to (without them the page ha
          +s nothing to render). Nedas's answers: sold-out best sellers l
          +eft out, 12 per block, and a new requirement: fill Best seller
          +s with all-time sales when the 30-day window has fewer than 12
          +, because production's seeded orders age out of the window aro
          +und 9 December.                                               
      233 +- Backend: `Services/Home` (`HomeLayout`, `HomeBanners`, `Best
          +Sellers`, `Deals`), `HomeController`, `HomeBlockResource`, `Ba
          +nnerResource`, four routes. 34 feature tests in `tests/Feature
          +/Home` plus 2 in `DemoSeedTest` (backend suite: 154); 23 rules
          + mutation-checked.                                            
      234 +- Frontend: `api/home.ts`, `features/home` (`HomePage`, `block
          +Registry`, `BannerBlock`, `ProductCarouselBlock`, `BlockLoadEr
          +ror`, pure rules in `homeBlocks.ts` with 16 Vitest tests, suit
          +e: 121), `ArrowButton`. Carousels reuse `ProductCard`. `HomePl
          +aceholder` removed.                                           
      235 +- Checked in a real browser (Playwright) on the local seed, de
          +sktop and mobile: banner stepping, wrap-around and dots, carou
          +sel scroll buttons, the banner CTA opening a Steam-filtered st
          +ore; then, by SQL (restored and diffed afterwards): reorder, r
          +ename, unknown type skipped, a second carousel row with no fro
          +ntend change, expired banner, external banner link skipped, a 
          +404 endpoint showing a per-block retry, no active blocks.     
      236 +- Measured the new queries on the 50k-product benchmark databa
          +se (dropped afterwards).                                      
      237 +                                                              
      238 +**Decisions**                                                 
      239 +- D32: best sellers ranked `recent_units DESC, total_units DES
          +C, id` in one aggregate (the all-time fill); deals ranked by t
          +he card's offer, not any discounted offer; no banner autoplay;
          + same-site banner links only; costs at scale recorded, not opt
          +imized.                                                       
      240 +                                                              
      241 +**Problems found and fixed**                                  
      242 +- The day 2 seed linked banners to `/store?platform[]=steam` (
          +API syntax); the store reads `platform=steam`. Fixed in the se
          +eder and, for production's already-seeded rows, by a data migr
          +ation.                                                        
      243 +- The carousel widened the whole page (962 px of sideways scro
          +ll): the cards' absolutely positioned `sr-only` labels escaped
          + the scroller's overflow clip. Fixed with `relative` on the sc
          +roller.                                                       
      244 +- Two quick Next clicks moved one banner (stale index); fixed 
          +with a functional state update.                               
      245 +- Two mutation-test mistakes of my own: one replacement hit a 
          +comment instead of the SQL, and one removed a tie-break whose 
          +absence PostgreSQL's row order happened to hide. Both re-run a
          +s targeted mutations and caught.                              
      246 +                                                              
      247 +**Open issues**                                               
      248 +- Best sellers (138 ms) and Deals (71 ms) cost grows with the 
          +catalog (benchmark); fine at 105 products, a short cache would
          + fix it at scale.                                             
      249 +- Block types and banner links are not validated on write (no 
          +admin UI); the frontend guards on read.                       
  ⎿  Updated docs/DECISIONS.md (+1 -1)
      237  - **Link safety**: endpoints only ever go through `apiGet` (re
           lative path appended to the API base, D4). Banner links are re
           ndered as in-app links only if they are same-site paths: `/…` 
           but not `//host` or `/\host` (both read as another host by bro
           wsers); otherwise the banner is skipped.
      238  - **Failure behaviour**: each block loads and fails on its own
            ("Could not load Deals. Try again"); an empty block is hidden
           ; no renderable block shows "Nothing featured right now" with 
           a link to the store; a failed layout shows a page-level retry.
            All checked in the browser by changing rows in SQL (reorder, 
           rename, unknown type, extra carousel, expired banner, external
            link, 404 endpoint, all blocks inactive), restored afterwards
           .
      239  - **Two frontend bugs found in the browser**: the cards' `sr-o
           nly` price labels are `position: absolute`, and an overflow co
           ntainer only clips absolute descendants when it is their conta
           ining block, so off-screen cards widened the whole page (the w
           indow scrolled 962 px sideways). The scroller is now `relative
           `. And two quick clicks on Next moved one slide (`index + 1` r
           ead a stale index); stepping now uses a functional state updat
           e.
      240 -- **Tests**: 34 backend feature tests (layout, banners, best s
          -ellers, deals, link migration) plus 2 in `DemoSeedTest`; 25 ru
          -les broken one at a time to confirm a test fails. 16 Vitest te
          -sts for the registry filter and the link rule, 5 mutation-chec
          -ked.                                                          
      240 +- **Tests**: 34 backend feature tests (layout, banners, best s
          +ellers, deals, link migration) plus 2 in `DemoSeedTest`; 23 ru
          +les broken one at a time to confirm a test fails. 16 Vitest te
          +sts for the registry filter and the link rule, 5 mutation-chec
          +ked.                                                          
      241  - **Cost, measured** (benchmark database, 50k products / 200k 
           offers / 1M keys, best of 5, SQL time): layout 0.5 ms, banners
            0.4 ms, **best sellers 138 ms, deals 71 ms**: both compute th
           e from price of every candidate before taking 12, like the sto
           re's `price_asc` sort (D23). Negligible at the real catalog's 
           105 products, so not optimized. At scale, a short cache (about
            a minute) would fix it; the product page and checkout re-chec
           k stock anyway (D30, D31).
      242  - **Gave up**: no autoplay; no per-block cache; the backend do
           es not validate block types or banner links on write (there is
            no admin UI; the frontend guards on read).

⏺ 154 backend tests pass, matching the log. Committing the docs and pushing:

  Ran 1 shell command (ctrl+o to expand)

⏺ The PR description still says sessions 2 and 3 are "to do". I'll fill both in
  (session 2 from its log entry) so the PR is accurate when it merges:

  Ran 1 shell command (ctrl+o to expand)

⏺ CI is running for the pushed commit. I'll wait for it in the background (gh pr
  checks --watch exits when every check finishes):

  Ran 1 shell command (ctrl+o to expand)

⏺ CI for PR #2 is running (backend, frontend, docker). I'll merge as soon as all
  three pass.

✻ Churned for 13m 19s · done 8:12 PM

⏺ Background command "Wait for PR #2 CI checks to finish" completed (exit code
0)

  Ran 1 shell command (ctrl+o to expand)

⏺ All checks are green on 25da575, which is the commit I pushed. Merging with a
  normal merge commit, as the working agreement says, then switching to main and
  pulling:

  Merged PR #2 (ctrl+o to expand)
⏺ The homepage is built, PR #2 is merged with a normal merge commit (c5a9215, so
  the day 4 commits stay in history), and you're on main, up to date with
  origin. CI was green on all checks (backend, frontend, docker, Vercel) before
  the merge.

  Backend
  - GET /home returns the active home_blocks rows in position order.
  - /home/banners, /home/best-sellers and /home/deals each come from their own
    service in Services/Home. The page can't show anything without them, so I
    built all four, as planned.
  - Carousels return the same card data as the store, so a new carousel only
    needs a database row, a service and a route. The frontend doesn't change.

  Best sellers with the all-time fill. One query adds up units sold per product
  two ways: last 30 days and all time. It sorts by recent units, then all-time
  units, then id. Anything sold in the last 30 days comes first, and all-time
  best sellers only take the places left over. Sold-out products are left out,
  12 at most.
  - Your test: every paid order is older than 30 days, and the block still
    returns 12 products ranked by all-time sales.
  - Against the real seed: DemoSeedTest jumps 90 days past the seed and still
    gets 12.

  Deals ranks products by the discount shown on their card (the cheapest
  in-stock offer). So a product can't appear in Deals for one seller's −60%
  offer while its card shows another seller's cheaper, undiscounted price.

  Frontend
  - The homepage looks up each block's type in a small registry (banner or
    product carousel) and skips unknown types. Titles, order and content all
    come from the API.
  - Carousels reuse ProductCard. Each block loads and fails on its own, with a
    Try again button.

  Bugs I found and fixed
  - Broken banner links: the seeded banners linked to /store?platform[]=steam,
    which the store doesn't read, so they opened an unfiltered store. I fixed
    the seeder and added a migration that rewrites those two links in
    production, which was seeded once and is never reseeded.
  - Page scrolled sideways (found in the browser): screen-reader price labels
    inside off-screen cards escaped the carousel's clipping and made the whole
    page scroll 962px sideways.
  - Banner stepping (found in the browser): two quick clicks on Next only moved
    one banner.

  Testing
  - 154 backend tests and 121 Vitest tests pass.
  - I broke 23 backend rules and 5 frontend rules one at a time, and a test
    caught every one.
  - In a real browser, on desktop and mobile, I changed the layout only in the
    database: reordered and renamed blocks, added an unknown type, added a
    second carousel row, expired a banner, pointed a block at a failing
    endpoint, and switched all blocks off. All behaved correctly with no
    frontend change. The tables were restored and checked against a backup
    afterwards.

  Known costs
  - On the 50k-product benchmark database, Best sellers takes 138 ms and Deals
    71 ms. That's negligible with the real 105 products, so I recorded it in D32
    instead of optimizing; a short cache would fix it at scale.
  - Banner autoplay was left out on purpose, for accessibility.

  Production redeploys from main automatically, and the banner link fix runs on
  container start. I haven't checked the live site yet.

  The two transcripts in ai-logs/ are still uncommitted, as last session, for
  your end-of-day commit.

✻ Cogitated for 33s · done 8:14 PM

※ recap: The goal was the server-driven homepage (day 4, session 3). It's built and tested, PR #2 is merged with green CI, and you're on an up-to-date main. Next: check the homepage on the live site once Render and Vercel finish redeploying. (disable recaps in /config)