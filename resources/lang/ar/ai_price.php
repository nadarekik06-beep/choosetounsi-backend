<?php

// محسّن الأسعار — نصوص الحساب الاحتياطي (عندما لا يستجيب Groq).
return [
    'strategy_entry'   => 'سعر دخول تنافسي',
    'strategy_market'  => 'سعر متماشٍ مع السوق',
    'reason_market'    => 'مبني على :count سعرًا حقيقيًا من السوق التونسية من :platforms (المتوسط: :avg د.ت)',
    'reason_platform'  => 'مبني على :count إعلانًا في ChooseTounsi ضمن هذا الصنف (المتوسط: :avg د.ت)',
    'reason_none'      => 'لا تتوفر بيانات عن السوق — توصية مبنية على السعر الحالي لصنف :category',
    'reason_current'   => 'سعرك الحالي :price د.ت :positioning.',
    'positioning'      => ['underpriced' => 'أقل من السوق', 'competitive' => 'تنافسي', 'overpriced' => 'أعلى من السوق', 'unknown' => 'يصعب تحديد موقعه'],
    'impact_entry'     => 'سعر دخول تنافسي من شأنه جذب أوائل المشترين على ChooseTounsi.',
    'impact_market'    => 'التماشي مع أسعار السوق يحافظ على نسبة التحويل مع تحسين رقم المعاملات.',
    'competitors_market'   => ':platforms: :count إعلانًا بين :min و:max د.ت.',
    'competitors_platform' => 'يضم ChooseTounsi :count منافسًا (المتوسط :avg د.ت).',
    'competitors_none'     => 'لم يتم العثور على بيانات عن المنافسين.',
    'overpriced'       => 'سعرك أعلى بـ:pct % من متوسط السوق — فكّر في تخفيضه لتحسين التحويل.',
    'room_to_increase' => 'سعرك أقل بـ:pct % من السوق — قد يكون لديك هامش لرفعه.',
    'no_sales'         => 'لا مبيعات بعد — تأكد من أن الإعلان يحتوي على صور كاملة.',
    'psycho'           => 'اعرض :psycho د.ت بدل :price د.ت — السعر النفسي يحقق مبيعات أفضل.',
];
