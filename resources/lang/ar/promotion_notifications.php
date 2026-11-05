<?php

// Seller notifications about promotions created by Choose'Tounsi (App\Notifications\Sellers\AdminPromotionNotification)
return [
    'greeting'  => 'مرحباً :name،',
    'details'   => 'التخفيض: :discount · من :starts إلى :ends.',
    'funding'   => 'كما في عروضك الخاصة، تُحسب أرباحك والعمولة على السعر بعد التخفيض.',
    'read_only' => "العروض التي تنشئها Choose'Tounsi تظهر في لوحة التحكم (العروض) للاطلاع فقط.",
    'cta'       => 'عرض عروضي',

    'created' => [
        'title' => "عرض جديد من Choose'Tounsi: :name",
        'body'  => "أطلقت Choose'Tounsi العرض «:name» (:discount) على: :products.",
    ],
    'updated' => [
        'title' => "تم تعديل عرض Choose'Tounsi: :name",
        'body'  => "عدّلت Choose'Tounsi العرض «:name» (:discount) على: :products.",
    ],
    'paused' => [
        'title' => "تم إيقاف عرض Choose'Tounsi مؤقتاً: :name",
        'body'  => "العرض «:name» متوقف مؤقتاً: عادت :products إلى سعرها العادي.",
    ],
    'resumed' => [
        'title' => "تم استئناف عرض Choose'Tounsi: :name",
        'body'  => "عاد العرض «:name» (:discount) على: :products.",
    ],
    'ended' => [
        'title' => "انتهى عرض Choose'Tounsi: :name",
        'body'  => "أنهت Choose'Tounsi العرض «:name»: عادت :products إلى سعرها العادي.",
    ],
    'replaced' => [
        'title' => "تم استبدال عرضك «:name»",
        'body'  => "نقلت Choose'Tounsi :products إلى عرضها الخاص: لم تعد ضمن عرضك «:name».",
    ],
];
