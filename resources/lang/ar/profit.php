<?php

// مركز الأرباح (Black Pepper): رسائل الأهداف، التنبيهات، التصدير.
return [
    'goal' => [
        'saved'           => 'تم حفظ الهدف.',
        'deleted'         => 'تم حذف الهدف.',
        'month_invalid'   => 'يمكنك تحديد هدف لهذا الشهر أو للشهر القادم فقط.',
        'net_above_sales' => 'لا يمكن أن يتجاوز هدف الأرباح الصافية هدف المبيعات.',
    ],
    'alerts' => [
        'saved' => 'تم حفظ تفضيلات التنبيه.',
    ],
    'notify' => [
        'cta'    => 'افتح مركز الأرباح',
        'footer' => 'تصلك هذه الرسالة لأنك فعّلت تنبيهات الهدف في مركز الأرباح. يمكنك إيقافها في أي وقت من جرس الهدف.',

        'milestone_title'      => 'بلغت :pct% من هدفك',
        'milestone_body'       => ':sales من أصل :goal في :month. يتبقى :remaining خلال :days يومًا.',
        'milestone_done_title' => 'تم بلوغ هدف :month!',
        'milestone_done_body'  => 'أحسنت: :sales من المبيعات لهدف قدره :goal.',

        'pace_title' => 'وتيرتك أقل من هدفك',
        'pace_body'  => 'التوقع: :projection لهدف قدره :goal. تحتاج :required يوميًا لمدة :days يومًا (حاليًا :current يوميًا).',

        'weekly_title'   => 'ملخص أسبوعك',
        'weekly_body'    => 'آخر 7 أيام: :week_sales (:orders طلبات).',
        'weekly_goal'    => 'هدف :month: تم بلوغ :pct%، التوقع :projection.',
        'weekly_no_goal' => 'حدّد هدفًا لمتابعة وتيرتك.',

        'recap_title'    => 'حصيلة :month',
        'recap_hit'      => '{1} تم بلوغ الهدف: :sales مقابل :goal (:pct%). الأرباح الصافية: :net. بدأت أول سلسلة لك!|[2,*] تم بلوغ الهدف: :sales مقابل :goal (:pct%). الأرباح الصافية: :net. السلسلة: :streak أشهر متتالية.|[0] تم بلوغ الهدف: :sales مقابل :goal (:pct%). الأرباح الصافية: :net.',
        'recap_missed'   => ':sales من أصل :goal (:pct%). الأرباح الصافية: :net. شهر جديد يبدأ: عدّل هدفك.',
        'recap_no_goal'  => 'المبيعات: :sales، الأرباح الصافية: :net.',

        'new_goal_title'     => 'شهر جديد، هدف جديد',
        'new_goal_body'      => 'حدّد هدف :month لمتابعة وتيرتك يومًا بيوم.',
        'new_goal_suggested' => 'حدّد هدف :month. اقتراح حسب أشهرك الأخيرة: :suggested.',
    ],
    'export' => [
        'title'      => 'مركز الأرباح — تقرير شهري',
        'gross'      => 'المبيعات الإجمالية',
        'refunds'    => 'المرتجعات المستردة',
        'commission' => 'عمولة Choose’Tounsi',
        'shipping'   => 'التوصيل على حسابك',
        'ads'        => 'الإعلانات (المحفظة)',
        'ads_credit' => 'الإعلانات (رصيد مجاني، غير مخصوم)',
        'net'        => 'الأرباح الصافية',
        'sales'      => 'المبيعات المؤكدة',
        'delivered'  => 'منها مُسلّمة',
        'orders'     => 'الطلبات المؤكدة',
        'goal'       => 'الهدف',
        'achieved'   => 'المُحقّق',
        'col' => [
            'order' => 'الطلب', 'date' => 'التاريخ', 'status' => 'الحالة', 'amount' => 'المبلغ (د.ت)',
            'commission' => 'العمولة (د.ت)', 'shipping' => 'التوصيل (د.ت)', 'net' => 'الصافي (د.ت)', 'payout' => 'الدفع',
        ],
        'status' => [
            'pending' => 'قيد الانتظار', 'confirmed' => 'مؤكدة', 'completed' => 'جاهزة', 'out_for_delivery' => 'قيد التوصيل',
            'delivered' => 'مُسلّمة', 'cancelled' => 'ملغاة', 'refunded' => 'مستردة',
        ],
    ],
];
