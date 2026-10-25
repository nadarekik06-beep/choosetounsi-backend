<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed gender menu for gendered top categories.
 *
 *   gender_menu_groups        one column of the mega-menu (Vêtements, Chaussures…) for one gender
 *   gender_menu_items         a subcategory placed in a column (same subcategory may sit in several)
 *   subcategory_gender_images optional round image per subcategory × gender (visual bar)
 *
 * A subcategory's genders = the genders of the columns it sits in. That is also what a
 * product with no seller gender inherits (one gender → that one, both → unisex).
 *
 * Seeded once from the storefront's former hardcoded MEGA list, so the menu looks the same
 * on day one. Seeding only runs when the groups table is empty and skips missing slugs.
 */
return new class extends Migration
{
    // Former Navbar.tsx MEGA["fashion-clothing"], with the FR/EN/AR labels from messages/*.json
    private array $groups = [
        ['key' => 'clothing',        'name' => 'Clothing',              'name_fr' => 'Vêtements',          'name_ar' => 'ملابس',                   'items' => ['dress', 't-shirt', 'shirt', 'jeans', 'denim-jacket', 'shorts', 'sweatshirt']],
        ['key' => 'shoes',           'name' => 'Shoes',                 'name_fr' => 'Chaussures',         'name_ar' => 'أحذية',                   'items' => ['high-heels', 'sneakers', 'sandals']],
        ['key' => 'bags',            'name' => 'Bags',                  'name_fr' => 'Sacs',               'name_ar' => 'حقائب',                   'items' => ['handbag', 'backpack']],
        ['key' => 'accessoriesBags', 'name' => 'Accessories & Bags',    'name_fr' => 'Accessoires & sacs', 'name_ar' => 'إكسسوارات وحقائب',        'items' => ['watch', 'scarf']],
        ['key' => 'underwear',       'name' => 'Underwear & Nightwear', 'name_fr' => 'Lingerie & nuit',    'name_ar' => 'ملابس داخلية وملابس نوم', 'items' => ['pyjama-set']],
        ['key' => 'cosmetics',       'name' => 'Cosmetics',             'name_fr' => 'Cosmétiques',        'name_ar' => 'مستحضرات التجميل',        'items' => ['eau-de-parfum']],
        ['key' => 'sportsLeisure',   'name' => 'Sports & Leisure',      'name_fr' => 'Sport & loisirs',    'name_ar' => 'رياضة وترفيه',            'items' => ['sweatshirt', 'sportswear', 'tracksuit']],
    ];

    // Left out of the Homme menu at seed time (admin can change it)
    private array $womenOnly = ['dress', 'high-heels', 'handbag'];

    public function up(): void
    {
        if (!Schema::hasTable('gender_menu_groups')) {
            Schema::create('gender_menu_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->string('gender', 10); // women | men
                $table->string('key', 60);
                $table->string('name');
                $table->string('name_fr')->nullable();
                $table->string('name_ar')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['category_id', 'gender', 'key']);
                $table->index(['category_id', 'gender', 'sort_order']);
            });
        }

        if (!Schema::hasTable('gender_menu_items')) {
            Schema::create('gender_menu_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('gender_menu_groups')->cascadeOnDelete();
                $table->foreignId('subcategory_id')->constrained('subcategories')->cascadeOnDelete();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['group_id', 'subcategory_id']);
                $table->index('subcategory_id');
            });
        }

        if (!Schema::hasTable('subcategory_gender_images')) {
            Schema::create('subcategory_gender_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subcategory_id')->constrained('subcategories')->cascadeOnDelete();
                $table->string('gender', 10); // women | men
                $table->string('image_path', 500);
                $table->timestamps();

                $table->unique(['subcategory_id', 'gender']);
            });
        }

        $this->seed();
    }

    private function seed(): void
    {
        if (DB::table('gender_menu_groups')->exists()) return;

        $fashionId = DB::table('categories')->where('slug', 'fashion-clothing')->value('id');
        if (!$fashionId) return;

        $now = now();

        foreach (['women', 'men'] as $gender) {
            foreach ($this->groups as $gi => $g) {
                $items = $gender === 'men'
                    ? array_values(array_diff($g['items'], $this->womenOnly))
                    : $g['items'];

                $subIds = [];
                foreach ($items as $slug) {
                    $id = $this->subcategoryId($slug, $fashionId);
                    if ($id) $subIds[] = $id;
                }
                if (!$subIds) continue;

                $groupId = DB::table('gender_menu_groups')->insertGetId([
                    'category_id' => $fashionId,
                    'gender'      => $gender,
                    'key'         => $g['key'],
                    'name'        => $g['name'],
                    'name_fr'     => $g['name_fr'],
                    'name_ar'     => $g['name_ar'],
                    'sort_order'  => $gi,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);

                foreach (array_values(array_unique($subIds)) as $ii => $subId) {
                    DB::table('gender_menu_items')->insert([
                        'group_id'       => $groupId,
                        'subcategory_id' => $subId,
                        'sort_order'     => $ii,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);
                }
            }
        }
    }

    /** The slug inside Mode & Vêtements first; otherwise the same slug in another category (e.g. eau-de-parfum). */
    private function subcategoryId(string $slug, int $fashionId): ?int
    {
        return DB::table('subcategories')->where('slug', $slug)->where('category_id', $fashionId)->value('id')
            ?? DB::table('subcategories')->where('slug', $slug)->orderBy('id')->value('id');
    }

    public function down(): void
    {
        Schema::dropIfExists('subcategory_gender_images');
        Schema::dropIfExists('gender_menu_items');
        Schema::dropIfExists('gender_menu_groups');
    }
};
