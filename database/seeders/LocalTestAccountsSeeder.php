<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerApplication;
use App\Models\User;
use App\Models\UserAddress;
use Database\Seeders\Concerns\DevelopmentOnly;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * LOCAL ONLY — accounts for clicking through the seller / admin / buyer flows,
 * plus a legacy product (5 colors in ONE variant, no size) like the ones the old
 * variant builder allowed.
 *
 *   php artisan db:seed --class=LocalTestAccountsSeeder
 *
 * Password: LOCAL_TEST_PASSWORD in the local .env (never committed). The e-mails use
 * the reserved .test domain, so nothing can be delivered to them.
 * Re-running resets the accounts and recreates the legacy product.
 */
class LocalTestAccountsSeeder extends Seeder
{
    use DevelopmentOnly;

    public const SELLER = 'seller.variants@choosetounsi.test';
    public const BUYER  = 'buyer.variants@choosetounsi.test';
    public const ADMIN  = 'admin.variants@choosetounsi.test';
    public const LEGACY_SLUG = 'test-legacy-multicolor-sneaker';

    public function run(): void
    {
        $this->refuseInProduction();

        $password = env('LOCAL_TEST_PASSWORD');
        if (blank($password) || strlen($password) < 12) {
            throw new RuntimeException('Set LOCAL_TEST_PASSWORD (12+ characters) in the local .env first.');
        }

        $seller = $this->user(self::SELLER, 'seller', 'Test', 'Vendeur', '22000001', $password);
        $this->user(self::BUYER, 'client', 'Test', 'Acheteur', '22000002', $password);
        $this->user(self::ADMIN, 'admin', 'Test', 'Admin', '22000003', $password);

        SellerApplication::updateOrCreate(['user_id' => $seller->id], [
            'full_name' => 'Test Vendeur', 'phone_number' => '22000001', 'business_name' => 'Boutique Test Variantes',
            'business_category' => 'fashion', 'wilaya' => 'Tunis', 'city' => 'Tunis', 'status' => 'approved', 'plan' => 'free',
        ]);

        $this->legacyProduct($seller);

        $this->command?->info('Local test accounts ready: ' . implode(', ', [self::SELLER, self::BUYER, self::ADMIN]) . ' (password: LOCAL_TEST_PASSWORD).');
    }

    private function user(string $email, string $role, string $first, string $last, string $phone, string $password): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->password = Hash::make($password);
        $user->fill([
            'name' => "$first $last", 'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
            'role' => $role, 'is_active' => true, 'is_approved' => true,
        ]);
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->forceFill(['onboarding_completed' => true]);
        $user->save();

        // A complete delivery address (copied from a local one when there is one)
        $sample = UserAddress::where('wilaya', '!=', '')->whereNotNull('delegation')->where('delegation', '!=', '')
            ->whereNotNull('intigo_city_id')->first();
        UserAddress::updateOrCreate(['user_id' => $user->id, 'label' => 'Test'], [
            'recipient_name' => "$first $last",
            'wilaya'         => $sample->wilaya ?? 'Tunis',
            'delegation'     => $sample->delegation ?? 'tunis',
            'intigo_city_id' => $sample->intigo_city_id ?? null,
            'intigo_district_id' => $sample->intigo_district_id ?? null,
            'city_name'      => $sample->city_name ?? null,
            'district_name'  => $sample->district_name ?? null,
            'address'        => '1 rue du Test',
            'postal_code'    => $sample->postal_code ?? '1000',
            'phone'          => $phone,
            'is_default'     => true,
        ]);

        return $user;
    }

    /** Noir + Blanc + Rouge + Rose + Beige in one variant, no size, one photo for the group. */
    private function legacyProduct(User $seller): void
    {
        $sub = DB::table('subcategories')->where('slug', 'sneakers')->first();
        if (!$sub) {
            $this->command?->warn('No "sneakers" subcategory: legacy product skipped.');
            return;
        }

        Product::withTrashed()->where('slug', self::LEGACY_SLUG)->get()->each(function (Product $p) {
            ProductImage::where('product_id', $p->id)->delete();
            $p->variants()->delete();
            $p->forceDelete();
        });

        $colorIds = DB::table('attribute_options as o')
            ->join('attributes as a', 'a.id', '=', 'o.attribute_id')
            ->where('a.slug', 'color')->whereIn('o.value', ['Black', 'White', 'Red', 'Pink', 'Beige'])
            ->pluck('o.id')->all();

        $product = Product::create([
            'seller_id' => $seller->id, 'category_id' => $sub->category_id, 'subcategory_id' => $sub->id,
            'name' => '[TEST] Basket ancienne multi-couleurs', 'slug' => self::LEGACY_SLUG,
            'description' => 'Produit de test local (ancien format de variantes).',
            'price' => 89, 'stock' => 50, 'parcel_size' => 'S', 'is_active' => true, 'is_approved' => true,
        ]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'stock' => 50, 'is_active' => true]);
        $variant->attributeOptions()->sync($colorIds);

        // One small image shared by the 5 colors (the old format stores it once per color)
        $path = 'products/test-legacy-multicolor.png';
        Storage::disk('public')->put($path, $this->png());
        foreach (array_values($colorIds) as $i => $cid) {
            ProductImage::create([
                'product_id' => $product->id, 'image_path' => $path, 'color_option_id' => $cid,
                'order' => 0, 'is_primary' => $i === 0,
            ]);
        }
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(400, 400);
        $stripes = [[0, 0, 0], [255, 255, 255], [220, 38, 38], [236, 72, 153], [212, 165, 116]];
        foreach ($stripes as $i => [$r, $g, $b]) {
            imagefilledrectangle($img, $i * 80, 0, $i * 80 + 79, 399, imagecolorallocate($img, $r, $g, $b));
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }
}
