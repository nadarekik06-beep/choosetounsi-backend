<?php

namespace App\Console\Commands;

use App\Services\Gender\GenderMenu;
use App\Services\Gender\GenderResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Active Mode & Vêtements products with NO gender, with a SUGGESTED gender.
 *
 *   php artisan products:suggest-gender                      read-only: prints the table, writes nothing
 *   php artisan products:suggest-gender --apply=272:women,274:unisex
 *                                                            sets ONLY the listed products (admin gender fix)
 *
 * The suggestion is a guess from the name, description and subcategory words
 * (femme / homme / robe / ...). "review" means no clear word: decide by looking at the product.
 * The images are not analysed: open the product in the admin to confirm.
 */
class SuggestProductGender extends Command
{
    protected $signature = 'products:suggest-gender {--category=fashion-clothing : category slug} {--apply= : id:gender pairs to write, e.g. 272:women,274:unisex}';
    protected $description = 'List untagged products of a gendered category with a suggested gender (read-only unless --apply)';

    /** Words that point to a gender (whole words, accents ignored). */
    private const WOMEN = ['femme', 'femmes', 'women', 'woman', 'womens', 'ladies', 'lady', 'dame', 'dames', 'fille', 'girl', 'robe', 'robes', 'dress',
        'dresses', 'jupe', 'jupes', 'skirt', 'talon', 'talons', 'escarpin', 'escarpins', 'heels', 'soutien', 'lingerie', 'nuisette', 'abaya', 'hijab',
        'foulard', 'foulards', 'cabas', 'maquillage', 'bikini', 'maillot de bain', 'legging', 'leggings', 'blouse', 'tunique', 'caftan',
        'نساء', 'نسائي', 'فستان', 'روبة', 'جيبة', 'عباية'];
    private const MEN = ['homme', 'hommes', 'men', 'mens', 'man', 'garcon', 'garçon', 'boy', 'costume', 'cravate', 'smoking', 'boxer', 'rasoir',
        'رجال', 'رجالي', 'بدلة'];
    private const UNISEX = ['unisex', 'unisexe', 'mixte', 'sweat', 'hoodie', 't-shirt', 'tshirt', 'jogging', 'casquette', 'bonnet', 'sneakers', 'basket',
        'baskets', 'jean', 'jeans'];

    public function handle(GenderResolver $resolver): int
    {
        if ($apply = trim((string) $this->option('apply'))) {
            return $this->apply($apply, $resolver);
        }

        $category = DB::table('categories')->where('slug', $this->option('category'))->first();
        if (!$category) {
            $this->error('Unknown category slug.');
            return self::FAILURE;
        }

        $rows = DB::table('products as p')
            ->join('users as u', 'u.id', '=', 'p.seller_id')
            ->leftJoin('subcategories as s', 's.id', '=', 'p.subcategory_id')
            ->where('p.category_id', $category->id)
            ->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.is_approved', true)
            ->whereNull('p.gender')
            ->orderBy('s.name')->orderBy('p.id')
            ->get(['p.id', 'p.name', 'p.short_description', 'p.description', 'p.gender_override', 'u.name as seller', 's.name as sub', 's.name_fr as sub_fr']);

        $table = [];
        foreach ($rows as $r) {
            [$gender, $why] = $this->suggest($r);
            $table[] = [$r->id, mb_substr($r->name, 0, 38), mb_substr($r->seller, 0, 22), $r->sub ?: '-', $gender, $why];
        }

        $this->table(['id', 'name', 'seller', 'subcategory', 'suggested', 'why'], $table);
        $this->info(count($table) . ' active product(s) without a gender in "' . $category->name . '". Nothing was changed.');
        $this->line('Apply only what you approve:  php artisan products:suggest-gender --apply=ID:gender,ID:gender   (women | men | unisex | kids)');

        return self::SUCCESS;
    }

    /** @return array{0:string,1:string} suggested gender (or "review") and the words that decided it */
    private function suggest(object $r): array
    {
        $text = mb_strtolower(implode(' ', [$r->name, $r->sub, $r->sub_fr, $r->short_description, mb_substr((string) $r->description, 0, 300)]));
        $hit  = fn (array $words) => array_values(array_filter($words, fn ($w) => preg_match('/(?<![\p{L}\p{N}])' . preg_quote($w, '/') . '(?![\p{L}\p{N}])/u', $text)));

        $women = $hit(self::WOMEN);
        $men   = $hit(self::MEN);
        $uni   = $hit(self::UNISEX);

        if ($women && !$men) {
            return ['women', implode(', ', array_slice($women, 0, 3))];
        }
        if ($men && !$women) {
            return ['men', implode(', ', array_slice($men, 0, 3))];
        }
        if ($women && $men) {
            return ['unisex', 'both genders named: ' . implode(', ', array_merge(array_slice($women, 0, 1), array_slice($men, 0, 1)))];
        }
        if ($uni) {
            return ['unisex', implode(', ', array_slice($uni, 0, 3)) . ' (usually worn by both)'];
        }

        return ['review', 'no clear word: look at the product'];
    }

    private function apply(string $pairs, GenderResolver $resolver): int
    {
        $done = 0;
        foreach (array_filter(array_map('trim', explode(',', $pairs))) as $pair) {
            [$id, $gender] = array_pad(explode(':', $pair, 2), 2, null);
            if (!ctype_digit((string) $id) || !in_array($gender, GenderResolver::ALL, true)) {
                $this->error("Skipped \"{$pair}\" (expected ID:women|men|unisex|kids).");
                continue;
            }
            if (!DB::table('products')->where('id', (int) $id)->exists()) {
                $this->error("Product {$id} does not exist.");
                continue;
            }
            DB::table('products')->where('id', (int) $id)->update(['gender_override' => $gender]);
            $resolver->sync((int) $id);
            $this->line("product {$id} → {$gender}");
            $done++;
        }
        app(GenderMenu::class)->flush();
        $this->info("{$done} product(s) updated (admin gender fix: gender_override).");

        return self::SUCCESS;
    }
}
