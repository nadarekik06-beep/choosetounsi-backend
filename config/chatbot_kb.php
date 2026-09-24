<?php

/*
|--------------------------------------------------------------------------
| Shopping assistant knowledge base (EN / FR / AR)
|--------------------------------------------------------------------------
|
| How-to answers for the chatbot. Every step here was checked against the
| storefront pages (button labels, URLs) and backend rules. Do not add a
| step you haven't confirmed in the code.
|
| Numbers are NOT written here. {placeholders} are filled at runtime by
| App\Services\Chat\KnowledgeBase from PlatformFacts (SellerSubscription
| constants, CommissionService, Complaint::COMPLAINT_WINDOW_HOURS), so the
| bot always matches what the backend actually enforces.
|
| Structure: topic → sections → { intro, steps[{title, text}], links, quick_replies }
| Each topic has a `default` section. Text values are ['en' =>, 'fr' =>, 'ar' =>].
|
*/

return [

    // ─────────────────────────────────────────────────────────────────────
    'become_vendor' => [
        'default' => 'apply',
        'sections' => [

            'apply' => [
                'intro' => [
                    'en' => "Opening your store on Choose'Tounsi is free. Here's how it works:",
                    'fr' => "Ouvrir votre boutique sur Choose'Tounsi est gratuit. Voici les étapes :",
                    'ar' => 'فتح متجرك على Choose\'Tounsi مجاني. هاهي الخطوات:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Log in', 'fr' => 'Connectez-vous', 'ar' => 'ادخل لحسابك'],
                     'text'  => ['en' => 'You need a Choose\'Tounsi account. Log in, then open the "Become a Seller" page.',
                                 'fr' => 'Il vous faut un compte Choose\'Tounsi. Connectez-vous puis ouvrez la page « Devenir vendeur ».',
                                 'ar' => 'لازمك حساب في Choose\'Tounsi. ادخل لحسابك وبعد افتح صفحة «كن بائعاً».']],
                    ['title' => ['en' => 'Fill in the application', 'fr' => 'Remplissez la demande', 'ar' => 'عمّر الطلب'],
                     'text'  => ['en' => 'The form has 3 steps: your full name, phone number and business name, your city, a profile picture and at least one product sample photo.',
                                 'fr' => 'Le formulaire a 3 étapes : nom complet, téléphone, nom de la boutique, ville, une photo de profil et au moins une photo d\'exemple de produit.',
                                 'ar' => 'الاستمارة فيها 3 مراحل: الاسم الكامل، رقم الهاتف، اسم النشاط، المدينة، صورة شخصية وعلى الأقل صورة وحدة لمنتج.']],
                    ['title' => ['en' => 'Wait for approval', 'fr' => 'Attendez la validation', 'ar' => 'استنى الموافقة'],
                     'text'  => ['en' => 'Our team reviews applications within 2–3 business days. If something needs fixing, you can edit and resubmit.',
                                 'fr' => 'Notre équipe étudie les demandes sous 2 à 3 jours ouvrables. Si quelque chose manque, vous pouvez modifier et renvoyer.',
                                 'ar' => 'فريقنا يراجع الطلبات في 2 لـ 3 أيام عمل. كان فما حاجة ناقصة، تنجم تبدّل وتبعث من جديد.']],
                    ['title' => ['en' => 'Add your products', 'fr' => 'Ajoutez vos produits', 'ar' => 'زيد منتجاتك'],
                     'text'  => ['en' => 'Once approved, go to your seller dashboard → Products → "Add Product". Each new product is checked by an admin before it goes live.',
                                 'fr' => 'Une fois validé, allez dans votre espace vendeur → Produits → « Add Product ». Chaque nouveau produit est vérifié par un admin avant sa mise en ligne.',
                                 'ar' => 'كي تتقبل، ادخل لفضاء البائع ← المنتجات ← «Add Product». كل منتج جديد يراجعو أدمين قبل ما يتنشر.']],
                    ['title' => ['en' => 'Upgrade if you want', 'fr' => 'Passez à un plan supérieur si vous voulez', 'ar' => 'رقّي باقتك كان تحب'],
                     'text'  => ['en' => 'You start on Green Pepper (free, up to {green_max} products). You can upgrade to Red or Black Pepper later from Seller → Subscription.',
                                 'fr' => 'Vous commencez avec Green Pepper (gratuit, jusqu\'à {green_max} produits). Vous pourrez passer à Red ou Black Pepper depuis Vendeur → Abonnement.',
                                 'ar' => 'تبدا بباقة Green Pepper (مجانية، حتى {green_max} منتج). من بعد تنجم ترقّي لـ Red ولا Black Pepper من فضاء البائع ← الاشتراك.']],
                ],
                'links' => [
                    ['label' => ['en' => 'Become a seller', 'fr' => 'Devenir vendeur', 'ar' => 'كن بائعاً'], 'url' => '/become-a-vendor'],
                ],
                'quick_replies' => ['vendor_plans', 'vendor_commission', 'vendor_add_products'],
            ],

            'plans' => [
                'intro' => [
                    'en' => 'There are 3 seller plans:',
                    'fr' => 'Il existe 3 plans vendeur :',
                    'ar' => 'فما 3 باقات للبائعين:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Green Pepper — free', 'fr' => 'Green Pepper — gratuit', 'ar' => 'Green Pepper — مجانية'],
                     'text'  => ['en' => 'Up to {green_max} products. Commission {green_commission} per sale.',
                                 'fr' => 'Jusqu\'à {green_max} produits. Commission de {green_commission} par vente.',
                                 'ar' => 'حتى {green_max} منتج. عمولة {green_commission} على كل بيعة.']],
                    ['title' => ['en' => 'Red Pepper — {red_price}/month', 'fr' => 'Red Pepper — {red_price}/mois', 'ar' => 'Red Pepper — {red_price} في الشهر'],
                     'text'  => ['en' => 'Up to {red_max} products. Commission {red_commission} per sale.',
                                 'fr' => 'Jusqu\'à {red_max} produits. Commission de {red_commission} par vente.',
                                 'ar' => 'حتى {red_max} منتج. عمولة {red_commission} على كل بيعة.']],
                    ['title' => ['en' => 'Black Pepper — {black_price}/month', 'fr' => 'Black Pepper — {black_price}/mois', 'ar' => 'Black Pepper — {black_price} في الشهر'],
                     'text'  => ['en' => 'Unlimited products. Commission {black_commission} per sale.',
                                 'fr' => 'Produits illimités. Commission de {black_commission} par vente.',
                                 'ar' => 'منتجات بلا حدود. عمولة {black_commission} على كل بيعة.']],
                    ['title' => ['en' => 'How to upgrade', 'fr' => 'Comment changer de plan', 'ar' => 'كيفاش ترقّي'],
                     'text'  => ['en' => 'Everyone starts on Green Pepper. Once your application is approved, upgrade from Seller → Subscription. The full feature list of each plan is on the Become a Seller page.',
                                 'fr' => 'Tout le monde commence avec Green Pepper. Une fois votre demande validée, changez de plan depuis Vendeur → Abonnement. La liste complète des avantages est sur la page Devenir vendeur.',
                                 'ar' => 'الكل يبدا بـ Green Pepper. كي يتقبل طلبك، ترقّي من فضاء البائع ← الاشتراك. قائمة المزايا الكاملة موجودة في صفحة «كن بائعاً».']],
                ],
                'links' => [
                    ['label' => ['en' => 'See the plans', 'fr' => 'Voir les plans', 'ar' => 'شوف الباقات'], 'url' => '/become-a-vendor'],
                    ['label' => ['en' => 'My subscription', 'fr' => 'Mon abonnement', 'ar' => 'اشتراكي'], 'url' => '/seller/subscription'],
                ],
                'quick_replies' => ['vendor_commission', 'vendor_apply'],
            ],

            'commission' => [
                'intro' => [
                    'en' => 'Choose\'Tounsi takes a commission on each sale. It depends on the product price and your plan:',
                    'fr' => 'Choose\'Tounsi prend une commission sur chaque vente. Elle dépend du prix du produit et de votre plan :',
                    'ar' => 'Choose\'Tounsi ياخذ عمولة على كل بيعة. العمولة تتبدّل حسب سوم المنتج والباقة متاعك:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Base rate by price', 'fr' => 'Taux de base selon le prix', 'ar' => 'النسبة الأساسية حسب السوم'],
                     'text'  => ['en' => '{tier_list}', 'fr' => '{tier_list}', 'ar' => '{tier_list}']],
                    ['title' => ['en' => 'Plan discount', 'fr' => 'Réduction selon le plan', 'ar' => 'تخفيض حسب الباقة'],
                     'text'  => ['en' => 'Red Pepper removes {red_reduction} points and Black Pepper {black_reduction} points. The rate never goes below {min_rate}%.',
                                 'fr' => 'Red Pepper retire {red_reduction} points et Black Pepper {black_reduction} points. Le taux ne descend jamais sous {min_rate} %.',
                                 'ar' => 'Red Pepper تنقص {red_reduction} نقاط و Black Pepper تنقص {black_reduction} نقاط. النسبة عمرها ما تهبط تحت {min_rate}٪.']],
                    ['title' => ['en' => 'What you pay', 'fr' => 'Ce que vous payez', 'ar' => 'قداش تخلص'],
                     'text'  => ['en' => 'Green Pepper {green_commission}, Red Pepper {red_commission}, Black Pepper {black_commission}.',
                                 'fr' => 'Green Pepper {green_commission}, Red Pepper {red_commission}, Black Pepper {black_commission}.',
                                 'ar' => 'Green Pepper {green_commission}، Red Pepper {red_commission}، Black Pepper {black_commission}.']],
                    ['title' => ['en' => 'With a coupon', 'fr' => 'Avec un coupon', 'ar' => 'مع كوبون'],
                     'text'  => ['en' => 'If you offer a coupon, the rate is chosen from the original price but applied to the price after your discount.',
                                 'fr' => 'Si vous offrez un coupon, le taux est choisi selon le prix d\'origine mais appliqué au prix après remise.',
                                 'ar' => 'كان تعطي كوبون، النسبة تتحدد حسب السوم الأصلي أما تتحسب على السوم بعد التخفيض.']],
                ],
                'links' => [
                    ['label' => ['en' => 'Become a seller', 'fr' => 'Devenir vendeur', 'ar' => 'كن بائعاً'], 'url' => '/become-a-vendor'],
                ],
                'quick_replies' => ['vendor_plans', 'vendor_apply'],
            ],

            'add_products' => [
                'intro' => [
                    'en' => 'Adding products once your seller account is approved:',
                    'fr' => 'Ajouter des produits une fois votre compte vendeur validé :',
                    'ar' => 'باش تزيد منتجات كي يتقبل حساب البائع متاعك:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Open your products', 'fr' => 'Ouvrez vos produits', 'ar' => 'افتح منتجاتك'],
                     'text'  => ['en' => 'Go to your seller dashboard → Products and tap "Add Product".',
                                 'fr' => 'Allez dans votre espace vendeur → Produits et cliquez sur « Add Product ».',
                                 'ar' => 'ادخل لفضاء البائع ← المنتجات واضغط على «Add Product».']],
                    ['title' => ['en' => 'Fill in the details', 'fr' => 'Remplissez les détails', 'ar' => 'عمّر التفاصيل'],
                     'text'  => ['en' => 'Name, description, category, price, stock and photos. Add variants (e.g. size or color) if your product has them.',
                                 'fr' => 'Nom, description, catégorie, prix, stock et photos. Ajoutez des variantes (taille, couleur…) si besoin.',
                                 'ar' => 'الاسم، الوصف، القسم، السوم، الكمية والتصاور. زيد الأنواع (مقاس، لون…) كان المنتج فيه.']],
                    ['title' => ['en' => 'Wait for review', 'fr' => 'Attendez la vérification', 'ar' => 'استنى المراجعة'],
                     'text'  => ['en' => 'New products show as "Pending" until an admin approves them.',
                                 'fr' => 'Les nouveaux produits restent « Pending » jusqu\'à validation par un admin.',
                                 'ar' => 'المنتجات الجديدة تبقى «Pending» حتى يوافق عليها أدمين.']],
                    ['title' => ['en' => 'Plan limits', 'fr' => 'Limites du plan', 'ar' => 'حدود الباقة'],
                     'text'  => ['en' => 'Green Pepper: up to {green_max} products, Red Pepper: {red_max}, Black Pepper: unlimited.',
                                 'fr' => 'Green Pepper : jusqu\'à {green_max} produits, Red Pepper : {red_max}, Black Pepper : illimité.',
                                 'ar' => 'Green Pepper: حتى {green_max} منتج، Red Pepper: {red_max}، Black Pepper: بلا حدود.']],
                ],
                'links' => [
                    ['label' => ['en' => 'My products', 'fr' => 'Mes produits', 'ar' => 'منتجاتي'], 'url' => '/seller/products'],
                ],
                'quick_replies' => ['vendor_plans'],
            ],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    'place_order' => [
        'default' => 'overview',
        'sections' => [

            'overview' => [
                'intro' => [
                    'en' => 'Ordering on Choose\'Tounsi takes a few steps:',
                    'fr' => 'Commander sur Choose\'Tounsi, c\'est simple :',
                    'ar' => 'باش تكوموندي على Choose\'Tounsi:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Find a product', 'fr' => 'Trouvez un produit', 'ar' => 'لوّج على منتج'],
                     'text'  => ['en' => 'Use the search bar, browse the categories, or ask me (e.g. "shoes under 100 DT").',
                                 'fr' => 'Utilisez la recherche, parcourez les catégories ou demandez-moi (ex. « chaussures moins de 100 DT »).',
                                 'ar' => 'استعمل البحث، تصفّح الأقسام، ولا اسألني (مثلاً «حذاء بأقل من 100 دينار»).']],
                    ['title' => ['en' => 'Choose options', 'fr' => 'Choisissez les options', 'ar' => 'اختار النوع'],
                     'text'  => ['en' => 'On the product page, pick the size or color if the product has variants, and the quantity.',
                                 'fr' => 'Sur la fiche produit, choisissez la taille ou la couleur si le produit a des variantes, puis la quantité.',
                                 'ar' => 'في صفحة المنتج، اختار المقاس ولا اللون كان فما أنواع، والكمية.']],
                    ['title' => ['en' => 'Add to cart', 'fr' => 'Ajoutez au panier', 'ar' => 'زيد للسلة'],
                     'text'  => ['en' => 'Tap "Add to Cart" (or "Buy Now" to order just that item). Open your cart from the cart icon.',
                                 'fr' => 'Cliquez sur « Add to Cart » (ou « Buy Now » pour commander seulement cet article). Ouvrez le panier depuis l\'icône panier.',
                                 'ar' => 'اضغط «Add to Cart» (ولا «Buy Now» باش تكوموندي المنتج هذا وحدو). افتح السلة من أيقونة السلة.']],
                    ['title' => ['en' => 'Checkout', 'fr' => 'Passez la commande', 'ar' => 'كمّل الطلب'],
                     'text'  => ['en' => 'In the cart, tap "Proceed to Checkout". You need to be logged in.',
                                 'fr' => 'Dans le panier, cliquez sur « Proceed to Checkout ». Vous devez être connecté.',
                                 'ar' => 'في السلة اضغط «Proceed to Checkout». لازمك تكون داخل لحسابك.']],
                    ['title' => ['en' => 'Address and coupon', 'fr' => 'Adresse et coupon', 'ar' => 'العنوان والكوبون'],
                     'text'  => ['en' => 'Enter your delivery address and phone. Have a seller coupon? Type it in that seller\'s coupon box (one coupon per seller).',
                                 'fr' => 'Indiquez votre adresse et téléphone. Un coupon vendeur ? Saisissez-le dans la case coupon de ce vendeur (un coupon par vendeur).',
                                 'ar' => 'اكتب عنوان التوصيل ورقم الهاتف. عندك كوبون متاع بائع؟ اكتبو في خانة الكوبون متاع البائع هذاكا (كوبون واحد لكل بائع).']],
                    ['title' => ['en' => 'Pay and confirm', 'fr' => 'Payez et confirmez', 'ar' => 'خلّص وأكّد'],
                     'text'  => ['en' => 'Choose Cash on Delivery, Wallet, D17 or Bank Card, then place your order.',
                                 'fr' => 'Choisissez paiement à la livraison, Wallet, D17 ou carte bancaire, puis validez.',
                                 'ar' => 'اختار الخلاص عند الاستلام، المحفظة، D17 ولا البطاقة البنكية، وبعد أكّد الطلب.']],
                ],
                'links' => [
                    ['label' => ['en' => 'Open cart', 'fr' => 'Ouvrir le panier', 'ar' => 'افتح السلة'], 'url' => '#cart'],
                    ['label' => ['en' => 'Shop now', 'fr' => 'Voir la boutique', 'ar' => 'تسوّق'], 'url' => '/shop'],
                ],
                'quick_replies' => ['order_payment', 'order_coupon', 'find_product'],
            ],

            'payment' => [
                'intro' => [
                    'en' => 'You can pay in 4 ways at checkout:',
                    'fr' => 'Vous pouvez payer de 4 façons :',
                    'ar' => 'تنجم تخلص بـ 4 طرق:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Cash on Delivery', 'fr' => 'Paiement à la livraison', 'ar' => 'الخلاص عند الاستلام'],
                     'text'  => ['en' => 'Pay in cash when your order arrives.',
                                 'fr' => 'Payez en espèces à la réception de la commande.',
                                 'ar' => 'تخلص كاش كي توصلك الطلبية.']],
                    ['title' => ['en' => 'Wallet', 'fr' => 'Wallet (portefeuille)', 'ar' => 'المحفظة'],
                     'text'  => ['en' => 'Pay with your Choose\'Tounsi wallet when the balance covers the whole total. Your balance is added by our team — contact support.',
                                 'fr' => 'Payez avec votre portefeuille Choose\'Tounsi si le solde couvre tout le total. Votre solde est ajouté par notre équipe — contactez le support.',
                                 'ar' => 'تخلص من محفظة Choose\'Tounsi كان الرصيد يغطي المبلغ الكل. الرصيد يزيدو فريقنا — اتصل بالدعم.']],
                    ['title' => ['en' => 'D17', 'fr' => 'D17', 'ar' => 'D17'],
                     'text'  => ['en' => 'Send the amount with the D17 app to the account shown at checkout, with your order number as the note. The order stays pending until our team confirms the transfer.',
                                 'fr' => 'Envoyez le montant avec l\'application D17 au compte indiqué au checkout, avec votre numéro de commande en note. La commande reste en attente jusqu\'à confirmation du virement.',
                                 'ar' => 'ابعث المبلغ بتطبيقة D17 للحساب اللي يظهر في صفحة الخلاص، واكتب رقم الطلبية في الملاحظة. الطلبية تبقى في الانتظار حتى فريقنا يأكّد التحويل.']],
                    ['title' => ['en' => 'Bank Card', 'fr' => 'Carte bancaire', 'ar' => 'بطاقة بنكية'],
                     'text'  => ['en' => 'Pay securely with Visa or Mastercard via Stripe.',
                                 'fr' => 'Payez en sécurité par Visa ou Mastercard via Stripe.',
                                 'ar' => 'خلّص بأمان بـ Visa ولا Mastercard عبر Stripe.']],
                ],
                'links' => [
                    ['label' => ['en' => 'Go to checkout', 'fr' => 'Aller au paiement', 'ar' => 'صفحة الخلاص'], 'url' => '/checkout'],
                ],
                'quick_replies' => ['order_coupon', 'order_how'],
            ],

            'coupon' => [
                'intro' => [
                    'en' => 'How seller coupons work:',
                    'fr' => 'Comment fonctionnent les coupons vendeur :',
                    'ar' => 'كيفاش تخدم كوبونات البائعين:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Get a code', 'fr' => 'Obtenez un code', 'ar' => 'خوذ كود'],
                     'text'  => ['en' => 'Coupons are created by sellers — you\'ll usually find them on the seller\'s store.',
                                 'fr' => 'Les coupons sont créés par les vendeurs — vous les trouverez en général sur leur boutique.',
                                 'ar' => 'الكوبونات يعملوهم البائعين — عادة تلقاهم في متجر البائع.']],
                    ['title' => ['en' => 'Apply at checkout', 'fr' => 'Appliquez-le au checkout', 'ar' => 'استعملو في الخلاص'],
                     'text'  => ['en' => 'At checkout, each seller has a coupon box. Type the code and apply it; the discount shows before you confirm.',
                                 'fr' => 'Au checkout, chaque vendeur a une case coupon. Saisissez le code et appliquez-le ; la remise s\'affiche avant validation.',
                                 'ar' => 'في صفحة الخلاص، كل بائع عندو خانة كوبون. اكتب الكود وطبّقو؛ التخفيض يبان قبل ما تأكّد.']],
                    ['title' => ['en' => 'Rules', 'fr' => 'Règles', 'ar' => 'القواعد'],
                     'text'  => ['en' => 'One coupon per seller. A coupon only works on that seller\'s products, and never on packs.',
                                 'fr' => 'Un coupon par vendeur. Il ne marche que sur les produits de ce vendeur, et jamais sur les packs.',
                                 'ar' => 'كوبون واحد لكل بائع. يخدم كان على منتجات البائع هذاكا، وما يخدمش على الـ packs.']],
                ],
                'links' => [
                    ['label' => ['en' => 'Go to checkout', 'fr' => 'Aller au paiement', 'ar' => 'صفحة الخلاص'], 'url' => '/checkout'],
                ],
                'quick_replies' => ['order_payment', 'find_product'],
            ],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    'track_order' => [
        'default' => 'statuses',
        'sections' => [
            'statuses' => [
                'intro' => [
                    'en' => 'Here is what each order status means:',
                    'fr' => 'Voici ce que signifie chaque statut de commande :',
                    'ar' => 'هذا معنى كل حالة متاع الطلبية:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Pending', 'fr' => 'En attente (Pending)', 'ar' => 'في الانتظار (Pending)'],
                     'text'  => ['en' => 'Order received and waiting to be confirmed. D17 orders wait here until the transfer is confirmed.',
                                 'fr' => 'Commande reçue, en attente de confirmation. Les commandes D17 restent ici jusqu\'à confirmation du virement.',
                                 'ar' => 'الطلبية وصلت وتستنى في التأكيد. طلبيات D17 تبقى هنا حتى يتأكد التحويل.']],
                    ['title' => ['en' => 'Processing / Confirmed', 'fr' => 'En cours / Confirmée', 'ar' => 'قيد المعالجة / مؤكدة'],
                     'text'  => ['en' => 'Your order has been accepted and is being prepared.',
                                 'fr' => 'Votre commande est acceptée et en préparation.',
                                 'ar' => 'الطلبية تقبلت وقاعدة تتحضّر.']],
                    ['title' => ['en' => 'Out for Delivery', 'fr' => 'En livraison', 'ar' => 'في الطريق'],
                     'text'  => ['en' => 'Your order is on its way to you.',
                                 'fr' => 'Votre commande est en route.',
                                 'ar' => 'الطلبية في الطريق ليك.']],
                    ['title' => ['en' => 'Delivered / Completed', 'fr' => 'Livrée / Terminée', 'ar' => 'وصلت / مكتملة'],
                     'text'  => ['en' => 'Your order has arrived. If something is wrong, you have {complaint_hours} hours after delivery to file a complaint.',
                                 'fr' => 'Votre commande est arrivée. En cas de problème, vous avez {complaint_hours} heures après la livraison pour faire une réclamation.',
                                 'ar' => 'الطلبية وصلت. كان فما مشكل، عندك {complaint_hours} ساعة بعد الاستلام باش تعمل شكوى.']],
                    ['title' => ['en' => 'Cancelled / Refunded', 'fr' => 'Annulée / Remboursée', 'ar' => 'ملغاة / مسترجعة'],
                     'text'  => ['en' => 'The order was cancelled, or its payment was refunded.',
                                 'fr' => 'La commande a été annulée, ou son paiement remboursé.',
                                 'ar' => 'الطلبية تلغات، ولا الفلوس ترجعت.']],
                ],
                'links' => [
                    ['label' => ['en' => 'My orders', 'fr' => 'Mes commandes', 'ar' => 'طلباتي'], 'url' => '/orders'],
                ],
                'quick_replies' => ['complaint_how'],
            ],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    'returns_complaints' => [
        'default' => 'file',
        'sections' => [
            'file' => [
                'intro' => [
                    'en' => 'Problem with an order? Here\'s how to file a complaint or ask for a refund:',
                    'fr' => 'Un problème avec une commande ? Voici comment faire une réclamation ou demander un remboursement :',
                    'ar' => 'عندك مشكل في طلبية؟ هكا تعمل شكوى ولا تطلب ترجيع الفلوس:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Check the time limit', 'fr' => 'Vérifiez le délai', 'ar' => 'ثبّت في الوقت'],
                     'text'  => ['en' => 'You can complain about a delivered order within {complaint_hours} hours after delivery — one complaint per order.',
                                 'fr' => 'Vous pouvez réclamer pour une commande livrée dans les {complaint_hours} heures après la livraison — une réclamation par commande.',
                                 'ar' => 'تنجم تشكي على طلبية وصلت في ظرف {complaint_hours} ساعة بعد الاستلام — شكوى وحدة لكل طلبية.']],
                    ['title' => ['en' => 'Open the complaint form', 'fr' => 'Ouvrez le formulaire', 'ar' => 'افتح استمارة الشكوى'],
                     'text'  => ['en' => 'Go to Complaints → new complaint and select your order and the items concerned.',
                                 'fr' => 'Allez dans Réclamations → nouvelle réclamation et choisissez la commande et les articles concernés.',
                                 'ar' => 'ادخل للشكاوي ← شكوى جديدة واختار الطلبية والمنتجات المعنية.']],
                    ['title' => ['en' => 'Describe the problem', 'fr' => 'Décrivez le problème', 'ar' => 'اشرح المشكل'],
                     'text'  => ['en' => 'Pick the reason (wrong product, wrong size, wrong color, damaged product or other) and describe it in at least 20 characters. You can add a photo.',
                                 'fr' => 'Choisissez le motif (mauvais produit, mauvaise taille, mauvaise couleur, produit endommagé ou autre) et décrivez-le en au moins 20 caractères. Vous pouvez ajouter une photo.',
                                 'ar' => 'اختار السبب (منتج غالط، مقاس غالط، لون غالط، منتج مكسور ولا سبب آخر) واشرحو في 20 حرف على الأقل. تنجم تزيد تصويرة.']],
                    ['title' => ['en' => 'Choose a solution', 'fr' => 'Choisissez une solution', 'ar' => 'اختار الحل'],
                     'text'  => ['en' => 'Ask for an exchange or a return & refund.',
                                 'fr' => 'Demandez un échange ou un retour avec remboursement.',
                                 'ar' => 'اطلب تبديل ولا ترجيع المنتج واسترجاع الفلوس.']],
                    ['title' => ['en' => 'Follow it up', 'fr' => 'Suivez-la', 'ar' => 'تابع الشكوى'],
                     'text'  => ['en' => 'Track it in My Complaints: pending → reviewing → approved or rejected. If the seller rejects it, our admin team reviews it. For refunds, a courier picks up the item.',
                                 'fr' => 'Suivez-la dans Mes réclamations : en attente → en examen → acceptée ou refusée. Si le vendeur refuse, notre équipe admin réexamine. Pour un remboursement, un livreur récupère l\'article.',
                                 'ar' => 'تابعها في «شكاويّ»: في الانتظار ← قيد المراجعة ← مقبولة ولا مرفوضة. كان البائع يرفض، فريق الإدارة يراجعها. في حالة الاسترجاع، موزّع يجي ياخو المنتج.']],
                ],
                'links' => [
                    ['label' => ['en' => 'File a complaint', 'fr' => 'Faire une réclamation', 'ar' => 'اعمل شكوى'], 'url' => '/complaints/new'],
                    ['label' => ['en' => 'My complaints', 'fr' => 'Mes réclamations', 'ar' => 'شكاويّ'], 'url' => '/complaints'],
                ],
                'quick_replies' => ['track_order'],
            ],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    'account_help' => [
        'default' => 'signup',
        'sections' => [
            'signup' => [
                'intro' => [
                    'en' => 'Creating your account:',
                    'fr' => 'Créer votre compte :',
                    'ar' => 'باش تعمل حساب:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Register', 'fr' => 'Inscrivez-vous', 'ar' => 'سجّل'],
                     'text'  => ['en' => 'Open the Register page, fill in your details and tap "Create Account".',
                                 'fr' => 'Ouvrez la page d\'inscription, remplissez vos informations et cliquez sur « Create Account ».',
                                 'ar' => 'افتح صفحة التسجيل، عمّر معلوماتك واضغط «Create Account».']],
                    ['title' => ['en' => 'Verify your email', 'fr' => 'Vérifiez votre e-mail', 'ar' => 'أكّد الإيميل'],
                     'text'  => ['en' => 'We send you a verification link by email. Open it to activate your account.',
                                 'fr' => 'Nous vous envoyons un lien de vérification par e-mail. Ouvrez-le pour activer votre compte.',
                                 'ar' => 'نبعثولك رابط تأكيد في الإيميل. افتحو باش تفعّل حسابك.']],
                    ['title' => ['en' => 'Or use Google', 'fr' => 'Ou utilisez Google', 'ar' => 'ولا استعمل Google'],
                     'text'  => ['en' => 'Tap "Continue with Google" on the login or register page to sign in with your Google account.',
                                 'fr' => 'Cliquez sur « Continue with Google » sur la page de connexion ou d\'inscription pour utiliser votre compte Google.',
                                 'ar' => 'اضغط «Continue with Google» في صفحة الدخول ولا التسجيل باش تدخل بحساب Google متاعك.']],
                ],
                'links' => [
                    ['label' => ['en' => 'Create account', 'fr' => 'Créer un compte', 'ar' => 'اعمل حساب'], 'url' => '/auth/register'],
                    ['label' => ['en' => 'Log in', 'fr' => 'Se connecter', 'ar' => 'ادخل'], 'url' => '/auth/login'],
                ],
                'quick_replies' => ['account_password', 'account_wallet'],
            ],

            'password' => [
                'intro' => [
                    'en' => 'Forgot your password?',
                    'fr' => 'Mot de passe oublié ?',
                    'ar' => 'نسيت كلمة السر؟',
                ],
                'steps' => [
                    ['title' => ['en' => 'Open "Forgot Password?"', 'fr' => 'Ouvrez « Forgot Password? »', 'ar' => 'افتح «Forgot Password?»'],
                     'text'  => ['en' => 'On the login page, tap "Forgot Password?".',
                                 'fr' => 'Sur la page de connexion, cliquez sur « Forgot Password? ».',
                                 'ar' => 'في صفحة الدخول، اضغط «Forgot Password?».']],
                    ['title' => ['en' => 'Get the reset link', 'fr' => 'Recevez le lien', 'ar' => 'خوذ الرابط'],
                     'text'  => ['en' => 'Enter your email and tap "Send Reset Link".',
                                 'fr' => 'Saisissez votre e-mail et cliquez sur « Send Reset Link ».',
                                 'ar' => 'اكتب الإيميل متاعك واضغط «Send Reset Link».']],
                    ['title' => ['en' => 'Set a new password', 'fr' => 'Choisissez un nouveau mot de passe', 'ar' => 'اختار كلمة سر جديدة'],
                     'text'  => ['en' => 'Open the link from the email and choose your new password.',
                                 'fr' => 'Ouvrez le lien reçu par e-mail et choisissez votre nouveau mot de passe.',
                                 'ar' => 'افتح الرابط اللي جاك في الإيميل واختار كلمة سر جديدة.']],
                    ['title' => ['en' => 'Signed up with Google?', 'fr' => 'Inscrit avec Google ?', 'ar' => 'سجّلت بـ Google؟'],
                     'text'  => ['en' => 'Just use "Continue with Google" to log in.',
                                 'fr' => 'Utilisez simplement « Continue with Google » pour vous connecter.',
                                 'ar' => 'استعمل «Continue with Google» باش تدخل.']],
                ],
                'links' => [
                    ['label' => ['en' => 'Reset password', 'fr' => 'Réinitialiser', 'ar' => 'رجّع كلمة السر'], 'url' => '/auth/forgot-password'],
                ],
                'quick_replies' => ['account_signup'],
            ],

            'wallet' => [
                'intro' => [
                    'en' => 'About your Choose\'Tounsi wallet:',
                    'fr' => 'À propos de votre portefeuille Choose\'Tounsi :',
                    'ar' => 'على محفظة Choose\'Tounsi:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Paying with it', 'fr' => 'Payer avec', 'ar' => 'الخلاص بيها'],
                     'text'  => ['en' => 'Choose "Wallet" at checkout. It works when your balance covers the whole order total.',
                                 'fr' => 'Choisissez « Wallet » au checkout. Cela fonctionne si votre solde couvre tout le total.',
                                 'ar' => 'اختار «Wallet» في صفحة الخلاص. تخدم كان الرصيد يغطي المبلغ الكل.']],
                    ['title' => ['en' => 'Adding money', 'fr' => 'Ajouter de l\'argent', 'ar' => 'زيادة الرصيد'],
                     'text'  => ['en' => 'Your balance is added by our team — contact support (support@choosetounsi.tn).',
                                 'fr' => 'Votre solde est ajouté par notre équipe — contactez le support (support@choosetounsi.tn).',
                                 'ar' => 'الرصيد يزيدو فريقنا — اتصل بالدعم (support@choosetounsi.tn).']],
                ],
                'links' => [
                    ['label' => ['en' => 'Go to checkout', 'fr' => 'Aller au paiement', 'ar' => 'صفحة الخلاص'], 'url' => '/checkout'],
                ],
                'quick_replies' => ['order_payment'],
            ],

            'profile' => [
                'intro' => [
                    'en' => 'Managing your account:',
                    'fr' => 'Gérer votre compte :',
                    'ar' => 'باش تتصرّف في حسابك:',
                ],
                'steps' => [
                    ['title' => ['en' => 'Profile', 'fr' => 'Profil', 'ar' => 'البروفيل'],
                     'text'  => ['en' => 'See your account details on your profile page. To change a forgotten password, use "Forgot Password?" on the login page.',
                                 'fr' => 'Consultez les informations de votre compte sur votre profil. Pour un mot de passe oublié, utilisez « Forgot Password? » sur la page de connexion.',
                                 'ar' => 'تلقى معلومات حسابك في صفحة البروفيل. كان نسيت كلمة السر، استعمل «Forgot Password?» في صفحة الدخول.']],
                    ['title' => ['en' => 'Addresses', 'fr' => 'Adresses', 'ar' => 'العناوين'],
                     'text'  => ['en' => 'Save your delivery addresses in Account → Addresses.',
                                 'fr' => 'Enregistrez vos adresses de livraison dans Compte → Adresses.',
                                 'ar' => 'سجّل عناوين التوصيل في الحساب ← العناوين.']],
                ],
                'links' => [
                    ['label' => ['en' => 'My profile', 'fr' => 'Mon profil', 'ar' => 'البروفيل متاعي'], 'url' => '/profile'],
                    ['label' => ['en' => 'My addresses', 'fr' => 'Mes adresses', 'ar' => 'عناويني'], 'url' => '/account/addresses'],
                ],
                'quick_replies' => ['account_password'],
            ],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────────
    // Quick replies referenced above: label shown on the button, message sent to the bot.
    'quick_replies' => [
        'find_product'        => ['label' => ['en' => 'Find a product', 'fr' => 'Trouver un produit', 'ar' => 'لوّج على منتج'],
                                  'message' => ['en' => 'Help me find a product', 'fr' => 'Aide-moi à trouver un produit', 'ar' => 'عاوني نلقى منتج']],
        'order_how'           => ['label' => ['en' => 'How to order', 'fr' => 'Comment commander', 'ar' => 'كيفاش نكوموندي'],
                                  'message' => ['en' => 'How do I place an order?', 'fr' => 'Comment passer une commande ?', 'ar' => 'كيفاش نعمل طلبية؟']],
        'order_payment'       => ['label' => ['en' => 'How do I pay?', 'fr' => 'Comment payer ?', 'ar' => 'كيفاش نخلص؟'],
                                  'message' => ['en' => 'How do I pay?', 'fr' => 'Comment payer ?', 'ar' => 'كيفاش نخلص؟']],
        'order_coupon'        => ['label' => ['en' => 'Coupons', 'fr' => 'Coupons', 'ar' => 'الكوبونات'],
                                  'message' => ['en' => 'How do coupons work?', 'fr' => 'Comment utiliser un coupon ?', 'ar' => 'كيفاش نستعمل كوبون؟']],
        'track_order'         => ['label' => ['en' => 'Track my order', 'fr' => 'Suivre ma commande', 'ar' => 'وين طلبيتي'],
                                  'message' => ['en' => 'Track my order', 'fr' => 'Où est ma commande ?', 'ar' => 'وين الطلبية متاعي؟']],
        'order_statuses'      => ['label' => ['en' => 'Order statuses', 'fr' => 'Statuts de commande', 'ar' => 'حالات الطلبية'],
                                  'message' => ['en' => 'What does each order status mean?', 'fr' => 'Que signifient les statuts de commande ?', 'ar' => 'شنوة معنى حالات الطلبية؟']],
        'complaint_how'       => ['label' => ['en' => 'Report a problem', 'fr' => 'Signaler un problème', 'ar' => 'عندي مشكل'],
                                  'message' => ['en' => 'How do I file a complaint?', 'fr' => 'Comment faire une réclamation ?', 'ar' => 'كيفاش نعمل شكوى؟']],
        'vendor_apply'        => ['label' => ['en' => 'Become a seller', 'fr' => 'Devenir vendeur', 'ar' => 'نحب نولّي بائع'],
                                  'message' => ['en' => 'How do I become a seller?', 'fr' => 'Comment devenir vendeur ?', 'ar' => 'كيفاش نحل بوتيك؟']],
        'vendor_plans'        => ['label' => ['en' => 'Seller plans', 'fr' => 'Plans vendeur', 'ar' => 'باقات البائعين'],
                                  'message' => ['en' => 'What are the seller plans?', 'fr' => 'Quels sont les plans vendeur ?', 'ar' => 'شنية باقات البائعين؟']],
        'vendor_commission'   => ['label' => ['en' => 'Commission', 'fr' => 'Commission', 'ar' => 'العمولة'],
                                  'message' => ['en' => 'How much is the commission?', 'fr' => 'Combien est la commission ?', 'ar' => 'قداش العمولة؟']],
        'vendor_add_products' => ['label' => ['en' => 'Add products', 'fr' => 'Ajouter des produits', 'ar' => 'نزيد منتجات'],
                                  'message' => ['en' => 'How do I add products?', 'fr' => 'Comment ajouter des produits ?', 'ar' => 'كيفاش نزيد منتج؟']],
        'account_signup'      => ['label' => ['en' => 'Create an account', 'fr' => 'Créer un compte', 'ar' => 'نعمل حساب'],
                                  'message' => ['en' => 'How do I create an account?', 'fr' => 'Comment créer un compte ?', 'ar' => 'كيفاش نعمل حساب؟']],
        'account_password'    => ['label' => ['en' => 'Forgot password', 'fr' => 'Mot de passe oublié', 'ar' => 'نسيت كلمة السر'],
                                  'message' => ['en' => 'I forgot my password', 'fr' => 'J\'ai oublié mon mot de passe', 'ar' => 'نسيت كلمة السر']],
        'account_wallet'      => ['label' => ['en' => 'Wallet', 'fr' => 'Portefeuille', 'ar' => 'المحفظة'],
                                  'message' => ['en' => 'How does the wallet work?', 'fr' => 'Comment marche le portefeuille ?', 'ar' => 'كيفاش تخدم المحفظة؟']],
        'show_cheaper'        => ['label' => ['en' => 'Show cheaper', 'fr' => 'Moins cher', 'ar' => 'أرخص'],
                                  'message' => ['en' => 'Show me cheaper ones', 'fr' => 'Montre-moi moins cher', 'ar' => 'وريني أرخص']],
        'show_more'           => ['label' => ['en' => 'Show others', 'fr' => 'Voir d\'autres', 'ar' => 'وريني أخرين'],
                                  'message' => ['en' => 'Show me other ones', 'fr' => 'Montre-moi d\'autres', 'ar' => 'وريني أخرين']],
        'other_categories'    => ['label' => ['en' => 'Other categories', 'fr' => 'Autres catégories', 'ar' => 'أقسام أخرى'],
                                  'message' => ['en' => 'What categories do you have?', 'fr' => 'Quelles catégories avez-vous ?', 'ar' => 'شنية الأقسام اللي عندكم؟']],
    ],
];
