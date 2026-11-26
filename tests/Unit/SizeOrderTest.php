<?php

namespace Tests\Unit;

use App\Support\SizeOrder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class SizeOrderTest extends TestCase
{
    private function assertSorted(array $expected, array $input): void
    {
        $this->assertSame($expected, SizeOrder::sort($input));
    }

    public function test_letter_sizes(): void
    {
        $this->assertSorted(['XS', 'S', 'M', 'L'], ['S', 'M', 'XS', 'L']);
        $this->assertSorted(
            ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', '4XL', '5XL'],
            ['5XL', 'XXXL', 'L', 'XXS', '4XL', 'XXL', 'S', 'XL', 'XS', 'M']
        );
    }

    public function test_aliases_case_and_spaces(): void
    {
        $this->assertSorted(['xs', 'M', 'XL', '2XL'], ['XL', '2XL', 'M', 'xs']);
        $this->assertSorted([' Xl ', 'XXL', '3XL'], ['3XL', 'XXL', ' Xl ']);
        $this->assertSame(0, SizeOrder::compare('2XL', 'x x l'));
        $this->assertSame(0, SizeOrder::compare('3XL', 'XXXL'));
    }

    public function test_numbers_and_halves(): void
    {
        $this->assertSorted(['38', '40.5', '42'], ['42', '38', '40.5']);
        $this->assertSorted(['38', '38,5', '39'], ['39', '38,5', '38']);
        $this->assertSorted(['6 mois', '12-18 mois', '2 ans', '4 ans', '10 ans'], ['10 ans', '2 ans', '12-18 mois', '4 ans', '6 mois']);
    }

    public function test_one_size_goes_last(): void
    {
        $this->assertSorted(['Taille unique'], ['Taille unique']);
        $this->assertSorted(['M', '40', 'Sur mesure', 'One Size'], ['One Size', 'Sur mesure', '40', 'M']);
        foreach (['TU', 'unique', 'مقاس واحد'] as $value) {
            $this->assertSorted(['5XL', $value], [$value, '5XL']);
        }
    }

    public function test_unknown_values_are_kept_after_known_sizes_in_original_order(): void
    {
        $this->assertSorted(['S', 'L', '44', 'Zeta', 'Alpha', 'Grand'], ['Zeta', 'L', 'Alpha', '44', 'S', 'Grand']);
    }

    public function test_size_attribute_detection(): void
    {
        foreach ([['size', null], ['shoe-size', null], ['x', 'Taille'], ['x', 'Pointure'], ['x', 'المقاس'], ['taille-pantalon', null]] as [$slug, $name]) {
            $this->assertTrue(SizeOrder::isSizeAttribute($slug, $name), "$slug / $name");
        }
        foreach ([['color', 'Couleur'], ['screen-size', "Taille d'écran"], ['taille-ecran', null], ['storage', 'Stockage']] as [$slug, $name]) {
            $this->assertFalse(SizeOrder::isSizeAttribute($slug, $name), "$slug / $name");
        }
    }

    public function test_options_of_other_attributes_are_untouched(): void
    {
        $options = new Collection([(object) ['value' => '8GB'], (object) ['value' => '4GB']]);
        $this->assertSame($options, SizeOrder::options((object) ['slug' => 'ram', 'name' => 'RAM'], $options));
    }

    public function test_variants_keep_color_order_and_sort_sizes_inside_each_color(): void
    {
        $variants = new Collection([
            $this->variant(1, 'Red', 'M'), $this->variant(2, 'Blue', 'L'), $this->variant(3, 'Red', 'XS'),
            $this->variant(4, 'Blue', 'S'), $this->variant(5, 'Red', 'S'),
        ]);

        $sorted = SizeOrder::variants($variants);

        $this->assertSame([3, 5, 1, 4, 2], $sorted->pluck('id')->all());
        // The given collection is left as it was
        $this->assertSame([1, 2, 3, 4, 5], $variants->pluck('id')->all());
    }

    public function test_variant_without_a_size_goes_after_the_sized_ones(): void
    {
        $variants = new Collection([$this->variant(1, 'Red', null), $this->variant(2, 'Red', 'L'), $this->variant(3, 'Red', 'S')]);
        $this->assertSame([3, 2, 1], SizeOrder::variants($variants)->pluck('id')->all());
    }

    public function test_variants_without_size_keep_their_order(): void
    {
        $variants = new Collection([$this->variant(2, 'Red', null), $this->variant(1, 'Blue', null)]);
        $this->assertSame([2, 1], SizeOrder::variants($variants)->pluck('id')->all());
    }

    private function variant(int $id, string $color, ?string $size): object
    {
        $options = [(object) ['id' => $color === 'Red' ? 10 : 11, 'value' => $color, 'attribute' => (object) ['slug' => 'color', 'name' => 'Color']]];
        if ($size !== null) {
            $options[] = (object) ['id' => 100 + $id, 'value' => $size, 'attribute' => (object) ['slug' => 'size', 'name' => 'Size']];
        }
        return (object) ['id' => $id, 'attributeOptions' => $options];
    }
}
