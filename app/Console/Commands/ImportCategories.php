<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\GenderMenuGroup;
use App\Models\GenderMenuItem;
use App\Models\Subcategory;
use App\Services\Catalog\CategoryNameMatcher;
use App\Services\Gender\GenderMenu;
use App\Services\Gender\GenderResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the category list (database/seeders/data/choosetounsi_categories.csv).
 *
 *   php artisan categories:import --dry-run   # full plan, writes nothing
 *   php artisan categories:import             # writes; refuses while anything is ambiguous
 *
 * Zero duplicates: a CSV category / subcategory is matched to an existing one by slug, then by
 * FR / EN / AR name (case, accents, "&"/"et"/"and", plurals, hyphens and word order ignored).
 * A match is never renamed, re-slugged or moved; only empty FR / AR translations are filled.
 * Weaker resemblances (Talons ⊂ Talons hauts), same names in another category and duplicates
 * inside the CSV are ambiguous: they block the import until settled in
 * database/seeders/data/category_import_decisions.php.
 *
 * Additive and idempotent: never deletes, never touches products; a second run creates nothing.
 * New subcategories copy the attribute setup of the closest existing subcategory; the menu columns
 * (menu_group_fr) and Femme / Homme links go into the admin mega-menu tables.
 */
class ImportCategories extends Command
{
    protected $signature = 'categories:import
                            {--dry-run : Print the plan without writing anything}
                            {--file= : CSV path (default: database/seeders/data/choosetounsi_categories.csv)}
                            {--decisions= : Decisions file (default: database/seeders/data/category_import_decisions.php)}';

    protected $description = 'Import categories / subcategories / mega-menu columns from the category CSV, without duplicates';

    private const COLUMNS = ['category_slug', 'category_fr', 'category_en', 'category_ar', 'menu_group_fr', 'sort_order',
        'subcategory_slug', 'subcategory_fr', 'subcategory_en', 'subcategory_ar', 'genders'];

    // Attribute template per fashion column (normalized menu_group_fr key → existing subcategory slug)
    private const FASHION_TEMPLATES = [
        'vetement' => 't-shirt', 'lingerie nuit' => 't-shirt', 'tenue traditionnelle' => 't-shirt',
        'chaussure' => 'sneakers', 'sac' => 'handbag', 'accessoire' => 'watch', 'bijou' => 'watch',
    ];

    private CategoryNameMatcher $m;
    private array $cats = [];      // id → existing category
    private array $subs = [];      // id → existing subcategory
    private array $groups = [];    // id → menu column
    private array $subGroups = []; // subcategory id → [group id…]
    private array $subAttrs = [];  // subcategory id → [pivot row…]
    private array $attrSlugs = []; // attribute id → slug

    public function handle(CategoryNameMatcher $matcher): int
    {
        $this->m = $matcher;
        $dry = (bool) $this->option('dry-run');

        $path = $this->option('file') ?: database_path('seeders/data/choosetounsi_categories.csv');
        $rows = $this->readCsv($path);
        if ($rows === null) return self::FAILURE;

        $decisionsFile = $this->option('decisions') ?: database_path('seeders/data/category_import_decisions.php');
        $decisions = is_file($decisionsFile) ? (require $decisionsFile) : [];
        $columnNames = require database_path('seeders/data/category_import_columns.php');

        $this->load();
        $plan = $this->plan($rows, $decisions, $columnNames);
        $this->report($plan, $dry);

        if ($plan['ambiguous']) {
            $this->error(count($plan['ambiguous']) . ' ambiguous item(s): nothing ' . ($dry ? 'would be' : 'was') . ' written. '
                . 'Settle each one in database/seeders/data/category_import_decisions.php, then run the dry-run again.');
            return $dry ? self::SUCCESS : self::FAILURE;
        }
        if ($dry) {
            $this->info('[DRY RUN] Nothing was written.');
            return self::SUCCESS;
        }

        DB::transaction(fn () => $this->apply($plan));
        app(GenderMenu::class)->flush();
        $this->info('Import done.');
        return self::SUCCESS;
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    private function readCsv(string $path): ?array
    {
        if (!is_file($path)) { $this->error("CSV not found: {$path}"); return null; }
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        if ($header) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // UTF-8 BOM
        if ($header !== self::COLUMNS) {
            $this->error('Unexpected CSV columns: ' . implode(',', (array) $header));
            return null;
        }
        $rows = [];
        $line = 1;
        while (($r = fgetcsv($fh)) !== false) {
            $line++;
            if ($r === [null] || count(array_filter($r, fn ($v) => trim((string) $v) !== '')) === 0) continue;
            $row = array_map(fn ($v) => trim((string) $v), array_combine(self::COLUMNS, array_pad($r, count(self::COLUMNS), '')));
            $row['line'] = $line;
            $row['genders'] = array_values(array_intersect(GenderResolver::BROWSABLE, array_filter(explode(';', $row['genders']))));
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    private function load(): void
    {
        // Ids as int: the plan compares them strictly
        foreach (DB::table('categories')->get(['id', 'slug', 'name', 'name_fr', 'name_ar', 'is_gendered', 'order']) as $c) {
            $c->id = (int) $c->id; $c->is_gendered = (bool) $c->is_gendered;
            $this->cats[$c->id] = $c;
        }
        foreach (DB::table('subcategories')->orderBy('order')->orderBy('id')->get(['id', 'category_id', 'slug', 'name', 'name_fr', 'name_ar', 'order']) as $s) {
            $s->id = (int) $s->id; $s->category_id = (int) $s->category_id;
            $this->subs[$s->id] = $s;
        }
        foreach (DB::table('gender_menu_groups')->orderBy('sort_order')->orderBy('id')->get() as $g) {
            $g->id = (int) $g->id; $g->category_id = (int) $g->category_id;
            $this->groups[$g->id] = $g;
        }
        foreach (DB::table('gender_menu_items')->get(['group_id', 'subcategory_id']) as $i) $this->subGroups[(int) $i->subcategory_id][] = (int) $i->group_id;
        foreach (DB::table('subcategory_attributes')->orderBy('order')->get() as $a) $this->subAttrs[(int) $a->subcategory_id][] = (array) $a;
        $this->attrSlugs = DB::table('attributes')->pluck('slug', 'id')->all();
    }

    // ── Planning (no writes) ────────────────────────────────────────────────

    private function plan(array $rows, array $decisions, array $columnNames): array
    {
        $plan = ['categories' => [], 'subs' => [], 'ambiguous' => [], 'columns' => [], 'notes' => []];

        // 1. Categories
        foreach ($rows as $r) {
            $k = $r['category_slug'];
            if (isset($plan['categories'][$k])) continue;
            $plan['categories'][$k] = $this->planCategory($r, $decisions, $plan);
        }

        // 2. Subcategories
        $claimed = []; // existing sub id → row index (exact matches)
        foreach ($rows as $i => $r) {
            $p = $this->planSub($r, $plan['categories'][$r['category_slug']], $decisions);
            $p['row'] = $r;
            $plan['subs'][$i] = $p;
            if ($p['action'] === 'match') $claimed[$p['existing_id']][] = $i;
        }

        // Two CSV rows on the same existing subcategory
        foreach ($claimed as $subId => $idx) {
            if (count($idx) < 2) continue;
            foreach ($idx as $i) {
                if (($plan['subs'][$i]['how'] ?? '') === 'decision') continue;
                $plan['subs'][$i]['action'] = 'ambiguous';
                $plan['subs'][$i]['why'] = 'several CSV rows match the same existing subcategory ' . $this->subLabel($subId);
            }
        }

        // Duplicates inside the CSV (same slug or same name, any category)
        $seen = [];
        foreach ($plan['subs'] as $i => $p) {
            $r = $p['row'];
            $keys = array_merge(
                ['slug:' . $r['subcategory_slug']],
                array_map(fn ($k) => "name:$k", $this->m->keys([$r['subcategory_fr'], $r['subcategory_en'], $r['subcategory_ar']]))
            );
            foreach ($keys as $key) {
                if (isset($seen[$key]) && $seen[$key] !== $i) {
                    $j = $seen[$key];
                    foreach ([$i, $j] as $x) {
                        // A decision, or an exact slug match on an existing row, settles that row
                        if (in_array($plan['subs'][$x]['how'] ?? '', ['decision', 'slug'], true)) continue;
                        $other = $plan['subs'][$x === $i ? $j : $i]['row'];
                        $plan['subs'][$x]['action'] = 'ambiguous';
                        $plan['subs'][$x]['why'] = "duplicate inside the CSV: same {$this->keyLabel($key)} as {$other['category_slug']}/{$other['subcategory_slug']} (line {$other['line']})";
                    }
                } else {
                    $seen[$key] = $i;
                }
            }
        }

        // Weaker resemblances, only for rows that would be created
        foreach ($plan['subs'] as $i => $p) {
            if ($p['action'] !== 'new' || ($p['how'] ?? '') === 'decision') continue;
            $catId = $plan['categories'][$p['row']['category_slug']]['existing_id'] ?? null;
            if (!$catId) continue;
            $names = [$p['row']['subcategory_fr'], $p['row']['subcategory_en']];
            $cands = [];
            foreach ($this->subs as $s) {
                if ($s->category_id !== $catId || isset($claimed[$s->id])) continue;
                if ($this->m->related($names, [$s->name, $s->name_fr])) $cands[] = $s->id;
            }
            if ($cands) {
                $plan['subs'][$i]['action'] = 'ambiguous';
                $plan['subs'][$i]['why'] = 'looks like ' . implode(', ', array_map(fn ($id) => $this->subLabel($id), $cands));
                $plan['subs'][$i]['candidates'] = $cands;
            }
        }

        foreach ($plan['categories'] as $k => $c) if ($c['action'] === 'ambiguous') $plan['ambiguous'][] = "category {$k}";
        foreach ($plan['subs'] as $p) if ($p['action'] === 'ambiguous') $plan['ambiguous'][] = "{$p['row']['category_slug']}/{$p['row']['subcategory_slug']}";

        // 3. Translations, attributes, menu (only meaningful once nothing is ambiguous, computed anyway)
        $plan['drop'] = $this->planDrops($decisions['_remove_columns'] ?? [], $plan);
        $this->planMenu($plan, $columnNames, $decisions);
        $this->planAttributes($plan);

        return $plan;
    }

    private function planCategory(array $r, array $decisions, array &$plan): array
    {
        $base = ['csv' => $r, 'existing_id' => null, 'how' => null];
        // An exact slug always wins (also what makes a second run create nothing)
        if ($c = $this->catBySlug($r['category_slug'])) return array_merge($base, ['action' => 'match', 'how' => 'slug', 'existing_id' => $c->id]);
        $d = $decisions['category:' . $r['category_slug']] ?? null;
        if ($d === 'new') return array_merge($base, ['action' => 'new', 'how' => 'decision']);
        if (is_string($d) && str_starts_with($d, 'match:')) {
            $c = $this->catBySlug(substr($d, 6));
            if ($c) return array_merge($base, ['action' => 'match', 'how' => 'decision', 'existing_id' => $c->id]);
            return array_merge($base, ['action' => 'ambiguous', 'why' => "decision points to unknown category {$d}"]);
        }

        $names = [$r['category_fr'], $r['category_en'], $r['category_ar']];
        $same = array_values(array_filter($this->cats, fn ($c) => $this->m->same($names, [$c->name, $c->name_fr, $c->name_ar])));
        if (count($same) === 1) return array_merge($base, ['action' => 'match', 'how' => 'name', 'existing_id' => $same[0]->id]);
        if (count($same) > 1) return array_merge($base, ['action' => 'ambiguous', 'why' => 'same name as ' . implode(', ', array_map(fn ($c) => $c->slug, $same))]);

        $rel = array_values(array_filter($this->cats, fn ($c) => $this->m->related([$r['category_fr'], $r['category_en']], [$c->name, $c->name_fr])));
        if ($rel) return array_merge($base, ['action' => 'ambiguous', 'why' => 'looks like ' . implode(', ', array_map(fn ($c) => "{$c->slug} ({$c->name_fr})", $rel))]);

        return array_merge($base, ['action' => 'new', 'how' => 'none']);
    }

    private function planSub(array $r, array $catPlan, array $decisions): array
    {
        $catId = $catPlan['existing_id'];
        $d = $decisions['sub:' . $r['category_slug'] . '/' . $r['subcategory_slug']] ?? null;

        // 'new+place:<existing>': create, and show the narrower existing one in the same column
        $place = [];
        if (is_string($d) && str_starts_with($d, 'new+place:')) {
            $ex = $this->subByRef(substr($d, 10));
            if (!$ex) return ['action' => 'ambiguous', 'why' => "decision points to unknown subcategory {$d}"];
            $place = ['place_existing' => $ex->id];
        }

        // An exact slug in the category always wins (also what makes a second run create nothing)
        if ($catId) {
            foreach ($this->subs as $s) {
                if ($s->category_id === $catId && $s->slug === $r['subcategory_slug']) return ['action' => 'match', 'how' => 'slug', 'existing_id' => $s->id] + $place;
            }
        }

        if ($d === 'new' || $place) return ['action' => 'new', 'how' => 'decision'] + $place;
        if (is_string($d) && str_starts_with($d, 'match:')) {
            [$cs, $ss] = array_pad(explode('/', substr($d, 6), 2), 2, '');
            $c = $this->catBySlug($cs);
            $s = $c ? collect($this->subs)->first(fn ($x) => $x->category_id === $c->id && $x->slug === $ss) : null;
            return $s ? ['action' => 'match', 'how' => 'decision', 'existing_id' => $s->id]
                      : ['action' => 'ambiguous', 'why' => "decision points to unknown subcategory {$d}"];
        }

        $names = [$r['subcategory_fr'], $r['subcategory_en'], $r['subcategory_ar']];
        $slugKey = $this->slugKey($r['subcategory_slug']);

        if ($catId) {
            $same = array_values(array_filter($this->subs, fn ($s) => $s->category_id === $catId
                && ($this->slugKey($s->slug) === $slugKey || $this->m->same($names, [$s->name, $s->name_fr, $s->name_ar]))));
            if (count($same) === 1) return ['action' => 'match', 'how' => 'name', 'existing_id' => $same[0]->id];
            if (count($same) > 1) return ['action' => 'ambiguous', 'why' => 'same name as ' . implode(', ', array_map(fn ($s) => $this->subLabel($s->id), $same)), 'candidates' => array_column($same, 'id')];
        }

        // Same slug or name in another category
        $elsewhere = array_values(array_filter($this->subs, fn ($s) => $s->category_id !== $catId
            && ($this->slugKey($s->slug) === $slugKey || $this->m->same($names, [$s->name, $s->name_fr, $s->name_ar]))));
        if ($elsewhere) {
            return ['action' => 'ambiguous', 'why' => 'same name/slug in another category: ' . implode(', ', array_map(fn ($s) => $this->subLabel($s->id), $elsewhere)), 'candidates' => array_column($elsewhere, 'id')];
        }

        return ['action' => 'new', 'how' => 'none'];
    }

    private function planMenu(array &$plan, array $columnNames, array $decisions): void
    {
        $virtual = [];   // "catRef|gender|key" → planned column (existing id or new)
        $placed = [];    // "catRef|gender|subRef" → true (avoid double placement within the run)

        foreach ($plan['subs'] as $i => $p) {
            if ($p['action'] === 'ambiguous') continue;
            $r = $p['row'];
            $catPlan = $plan['categories'][$r['category_slug']];
            if ($catPlan['action'] === 'ambiguous') continue;

            $catId = $catPlan['existing_id'];
            $gendered = $catId ? (bool) $this->cats[$catId]->is_gendered : false;
            $menuGenders = $gendered ? $r['genders'] : [GenderResolver::MENU_ALL];
            if ($gendered && !$r['genders']) {
                $plan['notes'][] = "{$r['category_slug']}/{$r['subcategory_slug']}: no gender in the CSV, not placed in the Femme / Homme menu";
            }
            if (!$gendered && $r['genders']) {
                $plan['notes'][] = "{$r['category_slug']}/{$r['subcategory_slug']}: genders ignored (category is not gendered)";
            }

            $subId = $p['action'] === 'match' ? $p['existing_id'] : null;
            $subRef = $subId ?? "new:{$r['category_slug']}/{$r['subcategory_slug']}";
            $catRef = $catId ?? "new:{$r['category_slug']}";

            // Existing gendered links that the CSV does not list are kept (additive): just report them
            if ($subId && $gendered) {
                $has = $this->subMenuGenders($subId, $catId);
                $extra = array_diff($has, $r['genders']);
                if ($extra) $plan['notes'][] = "{$r['category_slug']}/{$r['subcategory_slug']}: already in " . implode(' + ', $extra) . ' menu, kept (CSV says ' . (implode(';', $r['genders']) ?: 'none') . ')';
            }

            foreach ($menuGenders as $g) {
                if ($subId && $catId && $this->subInMenu($subId, $catId, $g)) continue;
                if (isset($placed["$catRef|$g|$subRef"])) continue;
                $placed["$catRef|$g|$subRef"] = true;

                $col = $this->findColumn($catId, $g, $r['menu_group_fr'], $virtual, (string) $catRef, $columnNames, $plan, $decisions);
                $plan['subs'][$i]['menu'][] = ['gender' => $g, 'column' => $col];
            }

            // Narrower existing subcategory shown in the same column (moved out of its other columns of that menu)
            if (!empty($p['place_existing']) && $catId) {
                $ex = $p['place_existing'];
                $exCat = $this->subs[$ex]->category_id;
                foreach ($menuGenders as $g) {
                    $col = $this->findColumn($catId, $g, $r['menu_group_fr'], $virtual, (string) $catRef, $columnNames, $plan, $decisions);
                    $target = $virtual[$col]['existing_id'];
                    $current = array_values(array_filter($this->subGroups[$ex] ?? [], fn ($gid) =>
                        isset($this->groups[$gid]) && $this->groups[$gid]->category_id === $catId && $this->groups[$gid]->gender === $g));
                    if ($target && in_array($target, $current, true)) continue; // already there
                    $plan['subs'][$i]['place'][] = ['gender' => $g, 'column' => $col, 'sub' => $ex, 'remove_from' => $current];
                }
            }
        }
        $plan['columns'] = $virtual;
    }

    private function findColumn(?int $catId, string $gender, string $fr, array &$virtual, string $catRef, array $columnNames, array &$plan, array $decisions): string
    {
        $key = $this->m->key($fr);
        $vk = "$catRef|$gender|$key";
        if (isset($virtual[$vk])) return $vk;

        $cands = $catId ? array_filter($this->groups, fn ($g) => $g->category_id === $catId && $g->gender === $gender) : [];
        foreach ($cands as $g) {
            if ($this->m->key($g->name_fr ?: $g->name) === $key) {
                return $this->setVirtual($virtual, $vk, ['existing_id' => $g->id, 'how' => 'name', 'fr' => $g->name_fr, 'csv' => $fr, 'cat' => $catRef, 'gender' => $gender]);
            }
        }

        // Decision: 'column:<category>/<gender>/<CSV column>' => 'new' | 'match:<existing column FR name>'
        $catSlug = $catId ? $this->cats[$catId]->slug : substr($catRef, 4);
        $dk = "column:{$catSlug}/{$gender}/{$fr}";
        $d = $decisions[$dk] ?? null;
        $decided = $d === 'new';
        if (is_string($d) && str_starts_with($d, 'match:') && $catId) {
            foreach ($this->groups as $g) {
                if ($g->category_id === $catId && $g->gender === $gender && ($g->name_fr ?: $g->name) === substr($d, 6)) {
                    return $this->setVirtual($virtual, $vk, ['existing_id' => $g->id, 'how' => 'decision', 'fr' => $g->name_fr, 'csv' => $fr, 'cat' => $catRef, 'gender' => $gender]);
                }
            }
            $plan['ambiguous'][] = "column {$dk}: decision points to an unknown column";
        }

        if ($catId && !$decided) {
            foreach ($cands as $g) {
                if ($this->m->related([$fr], [$g->name_fr, $g->name])) {
                    $plan['ambiguous'][] = "column {$dk}: similar to existing column \"{$g->name_fr}\"";
                    return $this->setVirtual($virtual, $vk, ['existing_id' => $g->id, 'how' => 'related', 'fr' => $g->name_fr, 'csv' => $fr, 'cat' => $catRef, 'gender' => $gender]);
                }
            }
        }

        [$en, $ar] = $columnNames[$fr] ?? [null, null];
        if (!$en) $plan['ambiguous'][] = "column \"{$fr}\" has no EN/AR names in category_import_columns.php";
        return $this->setVirtual($virtual, $vk, ['existing_id' => null, 'how' => 'new', 'fr' => $fr, 'en' => $en, 'ar' => $ar, 'csv' => $fr, 'cat' => $catRef, 'gender' => $gender]);
    }

    /** '_remove_columns' patterns "<category>/<gender|*>/<column FR>" → existing column ids (gone on a rerun). */
    private function planDrops(array $patterns, array &$plan): array
    {
        $ids = [];
        foreach ($patterns as $pat) {
            [$cs, $gs, $name] = array_pad(explode('/', (string) $pat, 3), 3, '');
            $cat = $this->catBySlug($cs);
            if (!$cat) { $plan['notes'][] = "remove column {$pat}: unknown category"; continue; }
            foreach ($this->groups as $g) {
                if ($g->category_id === $cat->id && ($gs === '*' || $g->gender === $gs) && ($g->name_fr ?: $g->name) === $name) $ids[] = $g->id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function setVirtual(array &$virtual, string $vk, array $v): string
    {
        $virtual[$vk] = $v;
        return $vk;
    }

    private function planAttributes(array &$plan): void
    {
        $config = require database_path('seeders/data/category_import_attributes.php');
        $default = $config['default'] ?? ['brand'];

        foreach ($plan['subs'] as $i => $p) {
            if ($p['action'] !== 'new') continue;
            $r = $p['row'];
            $key = "{$r['category_slug']}/{$r['subcategory_slug']}";
            $catId = $plan['categories'][$r['category_slug']]['existing_id'] ?? null;
            $gendered = $catId && $this->cats[$catId]->is_gendered;
            $rows = [];
            $label = null;

            if ($gendered) {
                // Mode & Vêtements: the column's reference subcategory (T-shirt, Baskets, Sac à main, Montre)
                $slug = self::FASHION_TEMPLATES[$this->m->key($r['menu_group_fr'])] ?? 't-shirt';
                $t = collect($this->subs)->first(fn ($s) => $s->category_id === $catId && $s->slug === $slug);
                if ($t && !empty($this->subAttrs[$t->id])) { $rows = $this->subAttrs[$t->id]; $label = 'copied from ' . $this->subLabel($t->id); }
            } elseif (isset($config['copy'][$key])) {
                // A listed real match
                $t = $this->subByRef($config['copy'][$key]);
                if ($t && !empty($this->subAttrs[$t->id])) { $rows = $this->subAttrs[$t->id]; $label = 'copied from ' . $this->subLabel($t->id); }
                else $plan['notes'][] = "{$key}: copy source {$config['copy'][$key]} missing or without attributes, brand only";
            } elseif (isset($config['attributes'][$key])) {
                $rows = $this->attributeRows($config['attributes'][$key], $plan, $key);
                $label = 'listed';
            }

            if (!$rows) {
                $rows = $this->attributeRows($default, $plan, $key);
                $label = 'default (no real match)';
            }
            if (!$gendered) {
                $rows = array_values(array_filter($rows, fn ($a) => ($this->attrSlugs[$a['attribute_id']] ?? '') !== 'gender'));
            }
            $attrs = implode(', ', array_map(fn ($a) => ($this->attrSlugs[$a['attribute_id']] ?? '?') . (!empty($a['is_variant']) ? '*' : ''), $rows));
            $plan['subs'][$i]['template'] = "{$label} [{$attrs}]";
            $plan['subs'][$i]['attrs'] = $rows;
        }
    }

    /** Pivot rows for attribute slugs (not variant, not required); unknown slugs are reported. */
    private function attributeRows(array $slugs, array &$plan, string $for): array
    {
        $ids = array_flip($this->attrSlugs);
        $rows = [];
        foreach (array_values($slugs) as $n => $slug) {
            if (!isset($ids[$slug])) { $plan['notes'][] = "{$for}: attribute \"{$slug}\" does not exist, skipped"; continue; }
            $rows[] = ['attribute_id' => $ids[$slug], 'is_required' => 0, 'is_variant' => 0, 'order' => $n];
        }
        return $rows;
    }

    // ── Report ──────────────────────────────────────────────────────────────

    private function report(array $plan, bool $dry): void
    {
        $subs = collect($plan['subs']);
        $this->info(($dry ? '[DRY RUN] ' : '') . 'CSV rows: ' . $subs->count() . ' · categories: ' . count($plan['categories']));

        $this->line('');
        $this->line('<comment>CATEGORIES</comment>');
        foreach ($plan['categories'] as $k => $c) {
            $r = $c['csv'];
            $line = match ($c['action']) {
                'match'     => "= {$k} → existing {$this->cats[$c['existing_id']]->slug} ({$this->cats[$c['existing_id']]->name_fr}) [{$c['how']}]",
                'new'       => "+ {$k} NEW ({$r['category_fr']} / {$r['category_en']} / {$r['category_ar']})",
                default     => "? {$k} AMBIGUOUS: {$c['why']}",
            };
            $this->line('  ' . $line . $this->fillNote($c['existing_id'] ? $this->cats[$c['existing_id']] : null, $r['category_fr'], $r['category_ar']));
        }

        $this->line('');
        $this->line('<comment>SUBCATEGORIES MATCHED TO EXISTING (skipped, never renamed or moved)</comment>');
        foreach ($subs->where('action', 'match') as $p) {
            $r = $p['row']; $s = $this->subs[$p['existing_id']];
            $how = ['slug' => 'exact slug', 'name' => 'same name', 'decision' => 'decision'][$p['how']] ?? $p['how'];
            $this->line("  = {$r['category_slug']}/{$r['subcategory_slug']} \"{$r['subcategory_fr']}\" → {$this->subLabel($s->id)} \"{$s->name_fr}\" [{$how}]"
                . $this->fillNote($s, $r['subcategory_fr'], $r['subcategory_ar']));
        }

        $amb = $subs->where('action', 'ambiguous');
        $catAmb = array_filter($plan['categories'], fn ($c) => $c['action'] === 'ambiguous');
        if ($amb->isNotEmpty() || $catAmb || $plan['ambiguous']) {
            $this->line('');
            $this->line('<error>AMBIGUOUS — settle in category_import_decisions.php</error>');
            foreach ($catAmb as $k => $c) {
                $this->line("  ? category {$k}: {$c['why']}");
                $this->line("      'category:{$k}' => 'new',   // or 'match:<existing-category-slug>'");
            }
            foreach ($amb as $p) {
                $r = $p['row'];
                $this->line("  ? {$r['category_slug']}/{$r['subcategory_slug']} \"{$r['subcategory_fr']}\" / \"{$r['subcategory_en']}\": {$p['why']}");
                $first = isset($p['candidates'][0]) ? $this->subLabel($p['candidates'][0]) : '<category>/<subcategory>';
                $this->line("      'sub:{$r['category_slug']}/{$r['subcategory_slug']}' => 'new',   // or 'match:{$first}'");
            }
            foreach (array_filter($plan['ambiguous'], fn ($a) => str_starts_with($a, 'column ')) as $a) {
                $this->line("  ? {$a}");
                if (preg_match('/^column (column:.+?): similar to existing column "(.*)"$/u', $a, $mm)) {
                    $this->line("      '{$mm[1]}' => 'match:{$mm[2]}',   // or 'new'");
                }
            }
        }

        $this->line('');
        $this->line('<comment>SUBCATEGORIES TO CREATE</comment>');
        foreach ($subs->where('action', 'new')->groupBy(fn ($p) => $p['row']['category_slug']) as $cat => $ps) {
            $this->line("  + {$cat} (" . $ps->count() . '): ' . $ps->map(fn ($p) => $p['row']['subcategory_slug'])->implode(', '));
        }

        $this->line('');
        $this->line('<comment>ATTRIBUTES OF NEW SUBCATEGORIES (copied from)</comment>');
        foreach ($subs->where('action', 'new')->groupBy(fn ($p) => $p['template']) as $label => $ps) {
            $this->line("  {$label}");
            $this->line('     ← ' . $ps->map(fn ($p) => $p['row']['subcategory_slug'])->implode(', '));
        }
        $this->line('  (* = variant axis)');

        $this->line('');
        $this->line('<comment>MEGA-MENU COLUMNS</comment>');
        foreach ($plan['columns'] as $c) {
            $cat = str_starts_with((string) $c['cat'], 'new:') ? substr($c['cat'], 4) : $this->cats[(int) $c['cat']]->slug;
            $g = $c['gender'] === 'all' ? '' : " [{$c['gender']}]";
            $this->line(match ($c['how']) {
                'new'     => "  + {$cat}{$g}: new column \"{$c['fr']}\" (EN \"{$c['en']}\", AR \"{$c['ar']}\")",
                'related' => "  ? {$cat}{$g}: CSV \"{$c['csv']}\" ~ existing column \"{$c['fr']}\" (similar name: needs a decision)",
                'decision' => "  = {$cat}{$g}: CSV \"{$c['csv']}\" → existing column \"{$c['fr']}\" [decision]",
                default   => "  = {$cat}{$g}: CSV \"{$c['csv']}\" → existing column \"{$c['fr']}\"",
            });
        }
        foreach ($subs as $p) {
            foreach ($p['place'] ?? [] as $pl) {
                $c = $plan['columns'][$pl['column']];
                $from = $pl['remove_from'] ? ' (moved out of "' . implode('", "', array_map(fn ($g) => $this->groups[$g]->name_fr, $pl['remove_from'])) . '")' : '';
                $g = $pl['gender'] === 'all' ? '' : " [{$pl['gender']}]";
                $this->line("  > existing {$this->subLabel($pl['sub'])}{$g} shown in column \"{$c['fr']}\" next to new {$p['row']['subcategory_slug']}{$from}");
            }
        }
        foreach ($plan['drop'] as $gid) {
            $g = $this->groups[$gid];
            $items = collect($this->subGroups)->filter(fn ($gs) => in_array($gid, $gs, true))->keys()->map(fn ($sid) => $this->subs[$sid]->slug)->implode(', ');
            $this->line("  - remove column \"{$g->name_fr}\" [{$g->gender}] from {$this->cats[$g->category_id]->slug} menu (placements only: {$items})");
        }
        $placements = $subs->sum(fn ($p) => count($p['menu'] ?? []));
        $women = $subs->sum(fn ($p) => collect($p['menu'] ?? [])->where('gender', 'women')->count());
        $men = $subs->sum(fn ($p) => collect($p['menu'] ?? [])->where('gender', 'men')->count());
        $this->line("  Menu placements to add: {$placements} (Femme {$women}, Homme {$men}, other categories " . ($placements - $women - $men) . ')');

        if ($plan['notes']) {
            $this->line('');
            $this->line('<comment>NOTES</comment>');
            foreach (array_unique($plan['notes']) as $n) $this->line("  - {$n}");
        }

        $this->line('');
        $this->table(['', 'Count'], [
            ['Categories matched', collect($plan['categories'])->where('action', 'match')->count()],
            ['Categories to create', collect($plan['categories'])->where('action', 'new')->count()],
            ['Subcategories matched (exact slug)', $subs->where('action', 'match')->where('how', 'slug')->count()],
            ['Subcategories matched (same name)', $subs->where('action', 'match')->where('how', 'name')->count()],
            ['Subcategories matched (decision)', $subs->where('action', 'match')->where('how', 'decision')->count()],
            ['Subcategories to create', $subs->where('action', 'new')->count()],
            ['Ambiguous', count($plan['ambiguous'])],
            ['New menu columns', collect($plan['columns'])->where('how', 'new')->count()],
            ['Menu placements', $placements],
            ['Existing shown next to new', $subs->sum(fn ($p) => count($p['place'] ?? []))],
            ['Menu columns removed', count($plan['drop'])],
        ]);
    }

    private function fillNote(?object $existing, string $fr, string $ar): string
    {
        if (!$existing) return '';
        $f = [];
        if (blank($existing->name_fr) && $fr !== '') $f[] = "FR \"{$fr}\"";
        if (blank($existing->name_ar) && $ar !== '') $f[] = "AR \"{$ar}\"";
        return $f ? ' · fill ' . implode(', ', $f) : '';
    }

    // ── Writing ─────────────────────────────────────────────────────────────

    private function apply(array $plan): void
    {
        $catIds = [];
        $nextOrder = (int) DB::table('categories')->max('order');
        foreach ($plan['categories'] as $k => $c) {
            $r = $c['csv'];
            if ($c['action'] === 'match') {
                $catIds[$k] = $c['existing_id'];
                $this->fillTranslations(Category::class, $c['existing_id'], $r['category_fr'], $r['category_ar']);
                continue;
            }
            $cat = Category::create([
                'name' => $r['category_en'], 'name_fr' => $r['category_fr'], 'name_ar' => $r['category_ar'],
                'slug' => $k, 'is_active' => true, 'order' => ++$nextOrder, 'is_gendered' => false,
            ]);
            $catIds[$k] = $cat->id;
            $this->line("  + category {$k}");
        }

        $subIds = [];
        $touched = [];
        foreach ($plan['subs'] as $i => $p) {
            $r = $p['row'];
            if ($p['action'] === 'match') {
                $subIds[$i] = $p['existing_id'];
                $this->fillTranslations(Subcategory::class, $p['existing_id'], $r['subcategory_fr'], $r['subcategory_ar']);
                continue;
            }
            $sub = Subcategory::create([
                'category_id' => $catIds[$r['category_slug']],
                'name' => $r['subcategory_en'], 'name_fr' => $r['subcategory_fr'], 'name_ar' => $r['subcategory_ar'],
                'slug' => $r['subcategory_slug'], 'is_active' => true, 'order' => (int) $r['sort_order'],
            ]);
            $subIds[$i] = $sub->id;
            $now = now();
            foreach ($p['attrs'] ?? [] as $a) {
                $row = $a;
                unset($row['id']);
                $row['subcategory_id'] = $sub->id;
                $row['created_at'] = $now; $row['updated_at'] = $now;
                DB::table('subcategory_attributes')->insert($row);
            }
        }

        $colIds = [];
        foreach ($plan['subs'] as $i => $p) {
            foreach (array_merge($p['menu'] ?? [], array_map(fn ($pl) => ['gender' => $pl['gender'], 'column' => $pl['column'], 'only_column' => true], $p['place'] ?? [])) as $place) {
                $vk = $place['column'];
                if (!isset($colIds[$vk])) {
                    $c = $plan['columns'][$vk];
                    if ($c['existing_id']) {
                        $colIds[$vk] = $c['existing_id'];
                    } else {
                        $catId = str_starts_with((string) $c['cat'], 'new:') ? $catIds[substr($c['cat'], 4)] : (int) $c['cat'];
                        $key = Str::slug($c['en'] ?: $c['fr']) ?: 'column';
                        $base = $key; $n = 2;
                        while (GenderMenuGroup::where(['category_id' => $catId, 'gender' => $c['gender'], 'key' => $key])->exists()) $key = $base . '-' . $n++;
                        $colIds[$vk] = GenderMenuGroup::create([
                            'category_id' => $catId, 'gender' => $c['gender'], 'key' => $key,
                            'name' => $c['en'] ?: $c['fr'], 'name_fr' => $c['fr'], 'name_ar' => $c['ar'],
                            'sort_order' => (int) GenderMenuGroup::where(['category_id' => $catId, 'gender' => $c['gender']])->max('sort_order') + 1,
                        ])->id;
                    }
                }
                $groupId = $colIds[$vk];
                if (!empty($place['only_column'])) continue;
                GenderMenuItem::firstOrCreate(
                    ['group_id' => $groupId, 'subcategory_id' => $subIds[$i]],
                    ['sort_order' => (int) GenderMenuItem::where('group_id', $groupId)->max('sort_order') + 1]
                );
                if ($place['gender'] !== GenderResolver::MENU_ALL) $touched[] = $subIds[$i];
            }
        }

        foreach ($plan['subs'] as $p) {
            foreach ($p['place'] ?? [] as $pl) {
                $groupId = $colIds[$pl['column']] ?? $plan['columns'][$pl['column']]['existing_id'];
                if (!$groupId) continue;
                foreach ($pl['remove_from'] as $old) {
                    if ($old !== $groupId) GenderMenuItem::where(['group_id' => $old, 'subcategory_id' => $pl['sub']])->get()->each->delete();
                }
                GenderMenuItem::firstOrCreate(
                    ['group_id' => $groupId, 'subcategory_id' => $pl['sub']],
                    ['sort_order' => (int) GenderMenuItem::where('group_id', $groupId)->max('sort_order') + 1]
                );
                if ($pl['gender'] !== GenderResolver::MENU_ALL) $touched[] = $pl['sub'];
            }
        }

        foreach ($plan['drop'] as $gid) {
            $group = GenderMenuGroup::find($gid);
            if (!$group) continue;
            $touched = array_merge($touched, $group->items()->pluck('subcategory_id')->all());
            $group->items()->get()->each->delete();
            $group->delete();
        }

        // Products of subcategories that gained Femme / Homme links re-derive their inherited gender
        app(GenderResolver::class)->syncSubcategories($touched);

        $created = collect($plan['subs'])->where('action', 'new')->count();
        $this->line("  Created {$created} subcategorie(s), " . collect($plan['columns'])->where('how', 'new')->count() . ' column(s), '
            . collect($plan['subs'])->sum(fn ($p) => count($p['menu'] ?? [])) . ' menu placement(s).');
    }

    private function fillTranslations(string $model, int $id, string $fr, string $ar): void
    {
        $row = DB::table((new $model)->getTable())->where('id', $id)->first(['name_fr', 'name_ar']);
        $fill = [];
        if (blank($row->name_fr) && $fr !== '') $fill['name_fr'] = $fr;
        if (blank($row->name_ar) && $ar !== '') $fill['name_ar'] = $ar;
        if ($fill) $model::whereKey($id)->first()->update($fill);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** "<category slug>/<subcategory slug>" → existing subcategory */
    private function subByRef(string $ref): ?object
    {
        [$cs, $ss] = array_pad(explode('/', $ref, 2), 2, '');
        $c = $this->catBySlug($cs);
        if (!$c) return null;
        foreach ($this->subs as $x) if ($x->category_id === $c->id && $x->slug === $ss) return $x;
        return null;
    }

    private function catBySlug(string $slug): ?object
    {
        foreach ($this->cats as $c) if ($c->slug === $slug) return $c;
        return null;
    }

    private function subLabel(int $id): string
    {
        $s = $this->subs[$id];
        return ($this->cats[$s->category_id]->slug ?? '?') . '/' . $s->slug;
    }

    private function slugKey(string $slug): string
    {
        return $this->m->key(str_replace('-', ' ', $slug));
    }

    private function keyLabel(string $key): string
    {
        return str_starts_with($key, 'slug:') ? 'slug' : 'name';
    }

    private function subInMenu(int $subId, int $catId, string $gender): bool
    {
        foreach ($this->subGroups[$subId] ?? [] as $gid) {
            $g = $this->groups[$gid] ?? null;
            if ($g && $g->category_id === $catId && $g->gender === $gender) return true;
        }
        return false;
    }

    private function subMenuGenders(int $subId, int $catId): array
    {
        $out = [];
        foreach ($this->subGroups[$subId] ?? [] as $gid) {
            $g = $this->groups[$gid] ?? null;
            if ($g && $g->category_id === $catId && in_array($g->gender, GenderResolver::BROWSABLE, true)) $out[$g->gender] = true;
        }
        return array_keys($out);
    }
}
