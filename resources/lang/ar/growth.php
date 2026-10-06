<?php

// Growth Radar (لوحة البائع). نصوص البطاقات من هذه القوالب؛ Groq يعيد صياغة العنوان فقط، لا الأرقام.
return [
    'cards' => [
        'leaking_product' => [
            'headline' => '{0} :product حصل على :views مشاهدة دون أي طلب خلال 30 يومًا|{1} :product حصل على :views مشاهدة لكن طلبًا واحدًا فقط خلال 30 يومًا|[2,*] :product حصل على :views مشاهدة لكن :orders طلبات فقط خلال 30 يومًا',
            'recommendation' => [
                'photos'      => 'أضف صورًا واضحة (3 على الأقل، لديك :images) — المشترون يغادرون عندما لا يرون المنتج جيدًا',
                'description' => 'أعد كتابة الوصف بمولّد الذكاء الاصطناعي: الخامات، المقاسات، التوصيل',
                'price'       => 'تخفيض :pct% لمدة :days أيام ليقترب من سعر الفئة',
                'price_test'  => 'جرّب تخفيض :pct% لمدة :days أيام وتابع الطلبات',
            ],
        ],
        'price_position' => [
            'headline' => [
                'high' => ':product أغلى بـ :above_pct% من السعر الوسيط للفئة (:median د.ت)',
                'low'  => ':product يُباع جيدًا بسعر أقل بـ :below_pct% من السعر الوسيط للفئة (:median د.ت)',
            ],
            'recommendation' => [
                'high' => 'تخفيض :pct% لمدة :days أيام (:new_price د.ت) ثم قارن الطلبات',
                'low'  => 'ارفع السعر إلى :new_price د.ت',
            ],
        ],
        'hidden_demand' => [
            'headline' => 'بحث المشترون عن «:query» :searches مرة ولم يجدوا شيئًا تقريبًا',
            'recommendation' => 'أضف منتجًا يطابق «:query»',
        ],
        'warm_audience' => [
            'headline' => ':audience مشترين يريدون :product لكنهم لم يشتروه',
            'recommendation' => 'أرسل لهم قسيمة خاصة بـ :pct% صالحة :days أيام — هم فقط من يستطيع استعمالها',
        ],
        'seasonal' => [
            'headline' => ':event يبدأ بعد :days_until يومًا',
            'recommendation' => [
                'discount'   => 'ابدأ تخفيض :pct% على :count منتج(ات) يوم :start_date',
                'flash_sale' => 'أطلق تخفيضًا خاطفًا لمدة 48 ساعة (-:pct%) على :count منتج(ات) من :start_date',
            ],
        ],
        'dead_stock' => [
            'headline' => ':product لم يُبع منذ :days يومًا (:stock في المخزون)',
            'recommendation' => [
                'clearance'  => 'صرّفه: -:pct% لمدة :promo_days أيام، أو ضعه في حزمة مع منتج رائج',
                'visibility' => 'لا يراه أحد تقريبًا: موّله إعلانيًا، أو ضعه في حزمة مع منتج رائج',
            ],
        ],
        'promo_timing' => [
            'headline' => 'المشترون أكثر نشاطًا يوم :day حوالي الساعة :hour',
            'recommendation' => 'أطلق تخفيضًا خاطفًا لمدة :hours ساعة (-:pct%) على :product يوم :start_date الساعة :hour',
        ],
        'results' => [
            'headline' => [
                'win'     => ':kind على :product نجح',
                'loss'    => ':kind على :product لم يكن مربحًا',
                'neutral' => ':kind على :product لم يُحدث فرقًا واضحًا',
                'unclear' => 'لا يمكننا بعد معرفة إن نجح :kind على :product',
            ],
        ],
    ],

    'kinds' => [
        'discount' => 'التخفيض', 'flash_sale' => 'التخفيض الخاطف', 'coupon' => 'القسيمة', 'boost' => 'التمويل الإعلاني',
        'edit' => 'تحديث الصفحة', 'listing' => 'المنتج الجديد', 'bundle' => 'الحزمة',
    ],

    'unclear' => [
        'short_history'  => 'المنتج معروض منذ وقت قصير جدًا للمقارنة مع الأيام السابقة.',
        'overlap'        => 'كان هناك عرض أو تمويل آخر خلال أيام المقارنة.',
        'few_sales'      => 'المبيعات قليلة جدًا للتمييز بين تغيير حقيقي والصدفة.',
        'not_measurable' => 'هذا الإجراء ليست له فترة مبيعات قابلة للقياس.',
    ],

    'basis' => [
        'own'       => 'مبني على مبيعاتك وزياراتك.',
        'category'  => 'مقارنة بمنتجات مشابهة في هذه الفئة (5 متاجر على الأقل، دون ذكر أي متجر).',
        'subcategory' => 'مقارنة بمنتجات مشابهة في هذه الفئة الفرعية (5 متاجر على الأقل، دون ذكر أي متجر).',
        'platform'  => 'مقارنة بالمنصة كلها (5 متاجر على الأقل).',
        'fallback'  => 'لا توجد بيانات سوق كافية بعد: مقارنة بمعدل نموذجي، فاعتبرها تقديرًا تقريبيًا.',
        'measured'  => 'يعتمد على الأثر المقاس في المرة السابقة في هذه الفئة.',
        'default'   => 'لا يوجد أثر مقاس بعد لهذه المناسبة: تقدير حذر.',
        'none'      => 'لا توجد بيانات كافية لتقدير الأثر بالدينار بعد.',
        'learned'   => 'معدّل حسب تفاعل مشتريك مع إجراءاتك السابقة.',
    ],

    'notify' => [
        'title' => '{1} إجراء جديد في Growth Radar: حتى +:high د.ت|[2,*] :count إجراءات جديدة في Growth Radar، حتى +:high د.ت',
        'body'  => ':headline',
        'subject' => 'Growth Radar: إجراء جديد لمتجرك',
        'cta'   => 'افتح Growth Radar',
        'footer' => 'تصلك هذه الرسالة لأن لديك Black Pepper. بريد واحد في الأسبوع على الأكثر.',
        'result_title' => 'نتائج :kind جاهزة',
        'result_body'  => ':headline',
    ],

    'coupon' => [
        'title'     => ':discount على :product، لك وحدك',
        'body'      => 'استعمل الرمز :code عند الدفع — عرض خاص من :shop.',
        'subject'   => 'عرض خاص على :product',
        'preheader' => ':discount، محجوز لك.',
        'intro'     => 'لاحظ :shop أن :product أعجبك. هذا رمز لا يستطيع استعماله غيرك:',
        'expires'   => 'صالح حتى :date.',
    ],

    'errors' => [
        'audience_too_small' => 'لا يوجد عدد كافٍ من المشترين المهتمين الآن (يلزم :min على الأقل حتى لا يُستهدف أحد بمفرده). حاول لاحقًا.',
        'audience_product'   => 'يجب أن تشمل القسيمة الموجّهة منتج البطاقة.',
        'refresh_cooldown'   => 'تم تحديث Growth Radar قبل دقائق. حاول بعد :minutes دقيقة.',
    ],
];
