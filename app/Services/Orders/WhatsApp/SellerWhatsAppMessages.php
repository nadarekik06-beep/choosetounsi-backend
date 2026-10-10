<?php

namespace App\Services\Orders\WhatsApp;

use App\Models\SellerOrder;
use App\Support\SellerPickup;

/**
 * The WhatsApp texts sent to sellers — edit them here, nowhere else.
 *
 * One template per attempt (0 = initial notice, 1 = reminder, 2 = last
 * reminder) and language (users.preferred_language: fr by default, ar).
 * Placeholders: {name} seller's first name, {store} shop name, {order} order
 * number, {count} item count, {items} "article(s)" word, {link} the order in
 * the seller dashboard (FRONTEND_URL).
 */
class SellerWhatsAppMessages
{
    public const LANGUAGES = ['fr', 'ar'];

    /** « Marquer comme préparée » / «تأكيد التحضير» are the seller dashboard button labels (frontend messages: seller.orders.actions.prepared). */
    private const TEMPLATES = [
        'fr' => [
            0 => "Bonjour {name} 👋\n"
               . "Ici Choose'Tounsi. Bonne nouvelle : une nouvelle commande confirmée pour votre boutique *{store}* !\n\n"
               . "🧾 Commande : *{order}*\n"
               . "📦 {count} {items}\n\n"
               . "Merci de préparer le colis, puis de cliquer sur « Marquer comme préparée » dans votre espace vendeur :\n"
               . "{link}\n\n"
               . "Merci et bonne vente ! 🇹🇳",
            1 => "Bonjour {name}, petit rappel de Choose'Tounsi 🙂\n\n"
               . "La commande *{order}* ({count} {items}) de votre boutique *{store}* n'est pas encore marquée comme préparée.\n\n"
               . "Merci de la préparer et de cliquer sur « Marquer comme préparée » :\n"
               . "{link}",
            2 => "Bonjour {name}, dernier rappel de Choose'Tounsi ⚠️\n\n"
               . "La commande *{order}* de votre boutique *{store}* n'est toujours pas préparée. "
               . "Elle doit être préparée *aujourd'hui*, sinon elle risque d'être annulée, ce qui affecte la fiabilité de votre boutique.\n\n"
               . "Marquez-la « préparée » dès que c'est fait :\n"
               . "{link}",
        ],
        'ar' => [
            0 => "مرحبا {name} 👋\n"
               . "معك Choose'Tounsi. خبر سار: طلبية جديدة مؤكدة لمتجرك *{store}*!\n\n"
               . "🧾 رقم الطلبية: *{order}*\n"
               . "📦 عدد المنتجات: {count}\n\n"
               . "الرجاء تحضير الطرد ثم الضغط على «تأكيد التحضير» في فضاء البائع:\n"
               . "{link}\n\n"
               . "شكرا وبالتوفيق! 🇹🇳",
            1 => "مرحبا {name}، تذكير صغير من Choose'Tounsi 🙂\n\n"
               . "الطلبية *{order}* ({count} منتج) لمتجرك *{store}* لم يتم تأكيد تحضيرها بعد.\n\n"
               . "الرجاء تحضيرها والضغط على «تأكيد التحضير»:\n"
               . "{link}",
            2 => "مرحبا {name}، تذكير أخير من Choose'Tounsi ⚠️\n\n"
               . "الطلبية *{order}* لمتجرك *{store}* مازالت غير محضّرة. "
               . "يجب تحضيرها *اليوم*، وإلا قد يتم إلغاؤها، وهذا يؤثر على موثوقية متجرك.\n\n"
               . "اضغط على «تأكيد التحضير» بمجرد الانتهاء:\n"
               . "{link}",
        ],
    ];

    public function build(SellerOrder $parcel, int $attempt): string
    {
        $parcel->loadMissing(['order:id,order_number', 'seller.sellerApplication', 'items:id,seller_order_id,quantity']);
        $seller = $parcel->seller;

        $language = in_array($seller?->preferred_language, self::LANGUAGES, true) ? $seller->preferred_language : 'fr';
        $template = self::TEMPLATES[$language][min(max($attempt, 0), 2)];

        $pickup = SellerPickup::for($seller, $parcel->seller_id);
        $name   = trim((string) ($seller?->sellerApplication?->full_name ?: $seller?->name));
        $count  = (int) $parcel->items->sum('quantity');

        return strtr($template, [
            '{name}'  => $name === '' ? '' : preg_split('/\s+/u', $name)[0],
            '{store}' => $pickup['shop_name'] ?? $seller?->name ?? '',
            '{order}' => $parcel->order?->order_number ?? "#{$parcel->order_id}",
            '{count}' => $count,
            '{items}' => $count > 1 ? 'articles' : 'article',
            '{link}'  => self::orderLink($parcel),
        ]);
    }

    /** The parcel in the seller dashboard (opens its detail). */
    public static function orderLink(SellerOrder $parcel): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/seller/orders?order=' . $parcel->id;
    }
}
