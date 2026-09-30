<?php

// الدفع اليدوي (واتساب): شحن المحفظة الإعلانية وتغيير الخطة.
return [
    'errors' => [
        'method_disabled'        => 'الدفع عبر واتساب غير متاح حاليًا.',
        'not_seller'             => 'هذا الطلب متاح للبائعين المعتمدين فقط.',
        'amount_out_of_range'    => 'يجب أن يكون المبلغ بين :min و :max.',
        'invalid_amount'         => 'مبلغ غير صالح.',
        'too_many_pending'       => 'لديك بالفعل :count طلب(ات) شحن قيد الانتظار. انتظر معالجتها أو ألغِ أحدها.',
        'upgrade_pending'        => 'لديك بالفعل طلب تغيير خطة قيد الانتظار (:reference). ألغه لإنشاء طلب آخر.',
        'plan_unavailable'       => 'هذه الخطة غير متاحة.',
        'no_yearly_price'        => 'الخطة :plan ليس لها سعر سنوي.',
        'not_an_upgrade'         => 'الخطة :plan ليست أعلى من خطتك الحالية.',
        'subscription_suspended' => 'اشتراكك معلّق. يرجى التواصل مع الدعم.',
        'already_decided'        => 'تمت معالجة هذا الطلب مسبقًا (:status).',
        'not_found'              => 'الطلب غير موجود.',
        'use_payment_request'    => 'الدفع بالبطاقة غير متاح. أرسل طلب تغيير خطة (الدفع عبر واتساب).',
    ],
    'status' => [
        'pending'   => 'قيد الانتظار',
        'approved'  => 'مقبول',
        'rejected'  => 'مرفوض',
        'cancelled' => 'ملغى',
    ],
    'notif' => [
        'wallet_topup' => [
            'approved' => ['title' => 'تم تأكيد الشحن :reference', 'body' => 'تمت إضافة :amount د.ت إلى محفظتك الإعلانية. تُستأنف الحملات الموقوفة بسبب نفاد الرصيد تلقائيًا.'],
            'rejected' => ['title' => 'تم رفض الشحن :reference', 'body' => 'تم رفض طلب الشحن. السبب: :reason'],
        ],
        'plan_upgrade' => [
            'approved' => ['title' => 'خطة :plan مفعّلة', 'body' => 'تم استلام الدفع :reference (:amount د.ت). خطة :plan مفعّلة حتى :end.'],
            'rejected' => ['title' => 'تم رفض الطلب :reference', 'body' => 'تم رفض طلب الانتقال إلى خطة :plan. السبب: :reason'],
        ],
        'view' => 'عرض التفاصيل',
    ],
];
