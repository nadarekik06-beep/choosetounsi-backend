<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class FillReviewTagArabicLabels extends Migration
{
    private const AR = [
        'Good Quality'     => 'جودة ممتازة',
        'Same as Pictures' => 'مطابق للصور',
        'Fast Delivery'    => 'توصيل سريع',
        'Worth the Price'  => 'يستحق سعره',
        'Elegant'          => 'أنيق',
        'Well Packaged'    => 'تغليف جيد',
        'Great Service'    => 'خدمة رائعة',
        'Comfortable'      => 'مريح',
        'Will Repurchase'  => 'سأشتري مجدداً',
        'Bad Packaging'    => 'تغليف سيئ',
        'Wrong Item'       => 'منتج خاطئ',
        'Sizing Issue'     => 'مشكلة في المقاس',
        'Poor Quality'     => 'جودة ضعيفة',
        'Late Delivery'    => 'تأخّر التوصيل',
    ];

    public function up()
    {
        foreach (self::AR as $label => $ar) {
            DB::table('review_tags')->where('label', $label)->whereNull('label_ar')->update(['label_ar' => $ar]);
        }
    }

    public function down()
    {
        DB::table('review_tags')->whereIn('label', array_keys(self::AR))->update(['label_ar' => null]);
    }
}
