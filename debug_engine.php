<?php
// Save as: C:\xampp\htdocs\choosetounsi-backend\debug_engine.php
// Run: php debug_engine.php
// Delete after checking.

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$file    = __DIR__ . '/app/Services/ForecastEngine.php';
$content = file_get_contents($file);

echo "\n=== Constant Name Check ===\n";
echo "Has SEASON_MONTHLY_PROFILES : " . (str_contains($content, 'SEASON_MONTHLY_PROFILES') ? "YES ✓\n" : "NO ✗\n");
echo "Has SEASON_PROFILES         : " . (str_contains($content, 'SEASON_PROFILES')         ? "YES ✓\n" : "NO ✗\n");
echo "Has TUNISIA_COMMERCE_INDEX  : " . (str_contains($content, 'TUNISIA_COMMERCE_INDEX')  ? "YES ✓\n" : "NO ✗\n");
echo "Has TUNISIA_MONTHLY_INDEX   : " . (str_contains($content, 'TUNISIA_MONTHLY_INDEX')   ? "YES ✓\n" : "NO ✗\n");

echo "\n=== Reflection: all constants on ForecastEngine ===\n";
$ref = new ReflectionClass(\App\Services\ForecastEngine::class);
foreach ($ref->getConstants() as $name => $val) {
    $preview = is_array($val) ? '[array, ' . count($val) . ' keys]' : $val;
    echo "  {$name}: {$preview}\n";
}

echo "\n=== Test: buildProductMonthlyIndex for ['winter'] ===\n";
// Call the private method via reflection to see what index it returns
$engine = app(\App\Services\ForecastEngine::class);
try {
    $method = $ref->getMethod('buildProductMonthlyIndex');
    $method->setAccessible(true);
    $index = $method->invoke($engine, ['winter']);

    echo "Monthly index for winter product:\n";
    $months = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
               7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
    foreach ($index as $m => $v) {
        $bar = str_repeat('█', (int)($v * 20));
        echo "  {$months[$m]} (M{$m}): " . number_format($v, 4) . "  {$bar}\n";
    }

    echo "\n  Peak month should be: Dec or Nov (winter)\n";
    arsort($index);
    $peakMonth = array_key_first($index);
    echo "  Actual peak month: {$months[$peakMonth]} (M{$peakMonth}) = " . number_format($index[$peakMonth], 4) . "\n";
    echo "  Result: " . ($peakMonth >= 11 || $peakMonth <= 2 ? "CORRECT ✓ winter peak\n" : "WRONG ✗ not a winter month\n");

} catch (\Throwable $e) {
    echo "ERROR calling buildProductMonthlyIndex: " . $e->getMessage() . "\n";
}

echo "\n=== Test: parseDeclaredSeasons ===\n";
try {
    $method2 = $ref->getMethod('parseDeclaredSeasons');
    $method2->setAccessible(true);

    $testInputs = [
        '["winter"]',
        '["winter","ramadan"]',
        'winter',
        '["winter","eid_al_adha","new_year","eid_al_fitr","back_to_school"]',
    ];

    foreach ($testInputs as $input) {
        $result = $method2->invoke($engine, $input);
        echo "  Input: {$input}\n  Output: [" . implode(', ', $result) . "]\n\n";
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Season index for 5-season product (what your hoodie has) ===\n";
try {
    $method = $ref->getMethod('buildProductMonthlyIndex');
    $method->setAccessible(true);
    $fiveSeasons = ['winter','eid_al_adha','new_year','eid_al_fitr','back_to_school'];
    $index5 = $method->invoke($engine, $fiveSeasons);

    $months = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
               7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
    echo "Monthly index for 5-season product:\n";
    foreach ($index5 as $m => $v) {
        echo "  {$months[$m]}: " . number_format($v, 4) . "\n";
    }
    arsort($index5);
    $peak5 = array_key_first($index5);
    echo "  Peak: {$months[$peak5]} = " . number_format($index5[$peak5], 4) . "\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";