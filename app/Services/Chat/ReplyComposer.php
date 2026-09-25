<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Log;

/**
 * Step 3 of the chatbot: write the text shown above the product cards.
 *
 * Groq only ever sees the products we found and is told to use nothing else.
 * Its reply is then checked: any DT amount that isn't one of our prices, or any
 * link, gets the reply thrown away in favour of the template. Templates are
 * also used whenever Groq is unavailable, so the user always gets an answer.
 *
 * Greetings, order help and "no results" never call Groq (saves free quota).
 */
class ReplyComposer
{
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'fr' => 'French',
        'ar' => 'Arabic (simple, Tunisian-friendly; light Tunisian darija in Arabic script is fine)',
    ];

    public function __construct(private GroqClient $groq) {}

    // ── Products found ────────────────────────────────────────────────────

    public function products(string $lang, string $message, array $products, array $filters, array $turns): string
    {
        $reply = $this->askLlm($lang, $message, $products, $filters, $turns);

        if ($reply !== null && $this->isGrounded($reply, $products, $filters)) {
            return $reply;
        }

        return $this->productsTemplate($lang, $products, $filters);
    }

    private function askLlm(string $lang, string $message, array $products, array $filters, array $turns): ?string
    {
        $lines = [];
        foreach ($products as $i => $p) {
            $line = ($i + 1) . '. ' . $p['name'] . ' | ' . ($p['price_from'] ? 'from ' : '') . $this->money($p['price'], 'en');
            if ($p['old_price']) {
                $line .= ' (was ' . $this->money($p['old_price'], 'en') . ($p['flash_sale'] ? ', flash sale' : '') . ')';
            }
            if ($p['seller']) {
                $line .= ' | seller: ' . $p['seller'];
            }
            if ($p['rating']) {
                $line .= ' | ' . $p['rating'] . '/5 (' . $p['rating_count'] . ' reviews)';
            }
            $lines[] = $line;
        }

        $range = $this->rangeText($filters, 'en');

        $system = "You are the shopping assistant of Choose'Tounsi, a Tunisian marketplace. "
            . 'Reply in ' . self::LANGUAGE_NAMES[$lang] . ' only. '
            . 'Write 2-3 short friendly sentences that help the customer choose among the PRODUCTS below. '
            . 'Use ONLY these products and their exact prices in DT. Never mention any other product, brand, price, discount or feature. '
            . 'Only mention ratings, reviews or popularity when a rating is listed; never call a product a favorite or best-seller otherwise. '
            . 'Product cards with images and links are shown under your message: do not write links or a full list. No markdown.';

        $messages = [['role' => 'system', 'content' => $system]];
        foreach (array_slice($turns, -6) as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => mb_substr($turn['content'], 0, 200)];
        }
        $messages[] = ['role' => 'user', 'content' => 'Customer: ' . mb_substr($message, 0, 400)
            . ($range ? "\nPrice filter: {$range}" : '')
            . "\nPRODUCTS:\n" . implode("\n", $lines)];

        $reply = $this->groq->chat($messages, 'reply', false, 350);

        return $reply === null ? null : trim(preg_replace('/[*#_`]+/u', '', $reply));
    }

    /**
     * Rejects replies that contain links or DT amounts we did not provide.
     */
    private function isGrounded(string $reply, array $products, array $filters): bool
    {
        if (preg_match('/https?:\/\/|www\.|\]\(/iu', $reply)) {
            Log::warning('[ChatReply] Reply contained a link, using template');
            return false;
        }

        $allowed = [];
        foreach ($products as $p) {
            $allowed[] = (float) $p['price'];
            if ($p['old_price']) {
                $allowed[] = (float) $p['old_price'];
            }
        }
        foreach (['min_price', 'max_price'] as $k) {
            if (!empty($filters[$k])) {
                $allowed[] = (float) $filters[$k];
            }
        }

        $normalized = strtr($reply, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '']);
        preg_match_all('/(\d+(?:[.,]\d{1,3})?)\s*(?:dt|tnd|dinars?|د\.?\s?ت|دينار|دنانير)/iu', $normalized, $m);

        foreach ($m[1] as $amount) {
            $value = (float) str_replace(',', '.', $amount);
            $ok    = false;
            foreach ($allowed as $a) {
                if (abs($a - $value) < 0.01 || abs(round($a) - $value) < 0.01) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                Log::warning('[ChatReply] Reply mentioned an unknown price, using template', ['amount' => $amount]);
                return false;
            }
        }

        return true;
    }

    private function productsTemplate(string $lang, array $products, array $filters): string
    {
        $count = count($products);
        $range = $this->rangeText($filters, $lang);
        $top   = [];
        foreach (array_slice($products, 0, 3) as $p) {
            $top[] = $p['name'] . ' (' . ($p['price_from'] ? $this->fromWord($lang) . ' ' : '') . $this->money($p['price'], $lang) . ')';
        }
        $list = implode($lang === 'ar' ? '، ' : ', ', $top);

        switch ($lang) {
            case 'fr':
                return "J'ai trouvé {$count} produit" . ($count > 1 ? 's' : '') . ($range ? " {$range}" : '')
                    . " : {$list}. Cliquez sur une fiche pour voir les détails.";
            case 'ar':
                return "لقيت لك {$count} " . ($count > 1 ? 'منتجات' : 'منتج') . ($range ? " {$range}" : '')
                    . ": {$list}. اضغط على المنتج باش تشوف التفاصيل.";
            default:
                return "I found {$count} product" . ($count > 1 ? 's' : '') . ($range ? " {$range}" : '')
                    . ": {$list}. Tap a card to see the details.";
        }
    }

    // ── No results ────────────────────────────────────────────────────────

    /**
     * @param array $nearest ProductRetriever::nearest() result
     */
    public function noResults(string $lang, array $filters, array $nearest, ?string $refine = null): string
    {
        $what  = implode(' ', array_slice($filters['keywords'] ?? [], 0, 2));
        $range = $this->rangeText($filters, $lang);
        $hasRange = $range !== '';

        // Follow-up ("cheaper ones", "others") with nothing new to show.
        if (in_array($refine, ['cheaper', 'pricier', 'more'], true)) {
            $key = $refine;
            $texts = [
                'en' => ['cheaper' => 'Those are already the cheapest options I have for this search.',
                         'pricier' => 'Those are already the most premium options I have for this search.',
                         'more'    => "That's everything I have for this search right now."],
                'fr' => ['cheaper' => "Ce sont déjà les options les moins chères pour cette recherche.",
                         'pricier' => 'Ce sont déjà les options les plus haut de gamme pour cette recherche.',
                         'more'    => "C'est tout ce que j'ai pour cette recherche pour le moment."],
                'ar' => ['cheaper' => 'هاذوما أرخص حاجات عندي في هالبحث.',
                         'pricier' => 'هاذوما أغلى حاجات عندي في هالبحث.',
                         'more'    => 'هذا كل شي عندي في هالبحث توّا.'],
            ];
            $tail = [
                'en' => ' Try another product or category?',
                'fr' => ' Voulez-vous essayer un autre produit ou une autre catégorie ?',
                'ar' => ' تحب تجرّب منتج ولا قسم آخر؟',
            ];
            return $texts[$lang][$key] . $tail[$lang];
        }

        if ($nearest['count'] > 0 && $hasRange) {
            $min   = $this->money($nearest['min'], $lang);
            $max   = $this->money($nearest['max'], $lang);
            $same  = abs($nearest['min'] - $nearest['max']) < 0.001;
            switch ($lang) {
                case 'fr':
                    return "Je n'ai rien trouvé {$range}" . ($what ? " pour « {$what} »" : '') . ". "
                        . "Par contre, nous avons {$nearest['count']} article(s) " . ($same ? "à {$min}" : "entre {$min} et {$max}")
                        . '. Voulez-vous élargir votre budget ?';
                case 'ar':
                    return "ما لقيتش حاجة {$range}" . ($what ? " لـ «{$what}»" : '') . '. '
                        . "أما عندنا {$nearest['count']} منتج " . ($same ? "بـ {$min}" : "بين {$min} و {$max}")
                        . '. تحب نوسّع الميزانية؟';
                default:
                    return "I couldn't find anything {$range}" . ($what ? " for \"{$what}\"" : '') . '. '
                        . "We do have {$nearest['count']} item(s) " . ($same ? "at {$min}" : "between {$min} and {$max}")
                        . '. Would you like to widen your budget?';
            }
        }

        $category = match ($lang) {
            'ar'    => $nearest['category_ar'] ?? $nearest['category'] ?? null,
            'fr'    => $nearest['category_fr'] ?? $nearest['category'] ?? null,
            default => $nearest['category'] ?? null,
        };
        switch ($lang) {
            case 'fr':
                return "Désolé, je n'ai trouvé aucun produit" . ($what ? " pour « {$what} »" : '') . ($range ? " {$range}" : '') . ". "
                    . ($hasRange ? 'Essayez une fourchette de prix plus large, ' : 'Essayez ')
                    . ($category ? "de regarder la catégorie « {$category} »" : "un autre mot (ex. « robe », « chaussures », « miel »)")
                    . ' ou parcourez la boutique.';
            case 'ar':
                return 'سامحني، ما لقيتش منتجات' . ($what ? " لـ «{$what}»" : '') . ($range ? " {$range}" : '') . '. '
                    . ($hasRange ? 'جرّب ميزانية أوسع، ' : 'جرّب ')
                    . ($category ? "تشوف قسم «{$category}»" : 'كلمة أخرى (مثلاً «حذاء»، «فستان»، «عسل»)')
                    . ' ولا تصفّح المتجر.';
            default:
                return "Sorry, I couldn't find any products" . ($what ? " for \"{$what}\"" : '') . ($range ? " {$range}" : '') . '. '
                    . ($hasRange ? 'Try a wider price range, ' : 'Try ')
                    . ($category ? "browsing the \"{$category}\" category" : 'another word (e.g. "dress", "shoes", "honey")')
                    . ' or browse the shop.';
        }
    }

    // ── Knowledge-base answers ────────────────────────────────────────────

    /**
     * Short/general how-to questions get the KB intro as-is (0 Groq calls —
     * the steps card carries the details). Longer, specific questions
     * ("can I use a coupon on a pack?") are answered by Groq from the KB
     * section only; if the answer adds a number or link that isn't in the
     * section, or Groq fails / hits 429, the KB intro is used instead.
     *
     * @param array $kb KnowledgeBase::answer() result
     */
    public function knowledge(string $lang, string $message, array $kb): string
    {
        $words = preg_split('/\s+/u', trim($message), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 8) {
            return $kb['intro'];
        }

        $system = "You are the assistant of Choose'Tounsi, a Tunisian marketplace. "
            . 'Reply in ' . self::LANGUAGE_NAMES[$lang] . ' only. '
            . 'Answer the customer in 1-3 short sentences using ONLY the FACTS below. '
            . 'If the facts do not answer the question, say so briefly and point to the steps shown under your message. '
            . 'Never add rules, numbers, prices, delays or links that are not in the FACTS. No markdown.';

        $reply = $this->groq->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "FACTS:\n" . $kb['context'] . "\n\nCustomer: " . mb_substr($message, 0, 400)],
        ], 'kb_reply', false, 300);

        if ($reply === null) {
            return $kb['intro'];
        }
        $reply = trim(preg_replace('/[*#_`]+/u', '', $reply));

        if (!$this->onlyKnownNumbers($reply, $kb['context']) || preg_match('/https?:\/\/|www\./iu', $reply)) {
            Log::warning('[ChatReply] KB answer added facts, using KB text');
            return $kb['intro'];
        }

        return $reply;
    }

    /** Every number in $reply must appear in $source (both Western and Arabic digits). */
    private function onlyKnownNumbers(string $reply, string $source): bool
    {
        $digits = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];
        preg_match_all('/\d+(?:[.,]\d+)?/u', strtr($reply, $digits), $inReply);
        preg_match_all('/\d+(?:[.,]\d+)?/u', strtr($source, $digits), $inSource);

        $known = array_flip($inSource[0]);
        foreach ($inReply[0] as $number) {
            if (!isset($known[$number])) {
                return false;
            }
        }
        return true;
    }

    // ── Orders (track_order) ──────────────────────────────────────────────

    public function ordersIntro(string $lang, int $count): string
    {
        return [
            'en' => $count === 1 ? 'Here is your latest order:' : "Here are your {$count} latest orders:",
            'fr' => $count === 1 ? 'Voici votre dernière commande :' : "Voici vos {$count} dernières commandes :",
            'ar' => $count === 1 ? 'هاذي آخر طلبية متاعك:' : "هاذوما آخر {$count} طلبيات متاعك:",
        ][$lang];
    }

    public function noOrders(string $lang): string
    {
        return [
            'en' => "You don't have any orders yet. Want me to help you find something?",
            'fr' => "Vous n'avez pas encore de commande. Voulez-vous que je vous aide à trouver un produit ?",
            'ar' => 'ما عندك حتى طلبية للتوّا. تحب نعاونك تلقى حاجة؟',
        ][$lang];
    }

    public function loginForOrders(string $lang): string
    {
        return [
            'en' => 'Please log in to see your orders and their status. You can also ask me what each status means.',
            'fr' => 'Connectez-vous pour voir vos commandes et leur statut. Vous pouvez aussi me demander ce que signifie chaque statut.',
            'ar' => 'ادخل لحسابك باش تشوف طلبياتك وحالتها. تنجم زادة تسألني على معنى كل حالة.',
        ][$lang];
    }

    public function categoriesIntro(string $lang): string
    {
        return [
            'en' => 'Here are our categories — tap one to browse, or tell me what you need:',
            'fr' => 'Voici nos catégories — touchez-en une, ou dites-moi ce que vous cherchez :',
            'ar' => 'هاذي الأقسام متاعنا — اضغط على قسم، ولا قلّي شنوة تحب:',
        ][$lang];
    }

    // ── Other intents (templates only) ────────────────────────────────────

    public function greeting(string $lang): string
    {
        switch ($lang) {
            case 'fr':
                return "Bonjour ! 👋 Je suis l'assistant shopping de Choose'Tounsi. Dites-moi ce que vous cherchez et votre budget, par ex. « chaussures entre 50 et 100 DT ».";
            case 'ar':
                return 'أهلا وسهلا! 👋 أنا مساعد التسوق في Choose\'Tounsi. قلّي شنوة تلوّج عليه وقدّاش ميزانيتك، مثلاً «حذاء بين 50 و 100 دينار».';
            default:
                return "Hi! 👋 I'm Choose'Tounsi's shopping assistant. Tell me what you're looking for and your budget, e.g. \"shoes between 50 and 100 DT\".";
        }
    }

    public function other(string $lang): string
    {
        switch ($lang) {
            case 'fr':
                return "Je suis là pour vous aider à trouver des produits sur Choose'Tounsi. Dites-moi ce que vous cherchez, avec un budget si vous voulez.";
            case 'ar':
                return "أنا هنا باش نعاونك تلقى منتجات على Choose'Tounsi. قلّي شنوة تحب، وإذا تحب زيد الميزانية.";
            default:
                return "I'm here to help you find products on Choose'Tounsi. Tell me what you're looking for, with a budget if you like.";
        }
    }

    public function error(string $lang): string
    {
        switch ($lang) {
            case 'fr':
                return "Désolé, un problème technique est survenu. Réessayez dans un instant.";
            case 'ar':
                return 'سامحني، صارت مشكلة تقنية. عاود جرّب بعد لحظة.';
            default:
                return 'Sorry, something went wrong on our side. Please try again in a moment.';
        }
    }

    public function tooFast(string $lang): string
    {
        switch ($lang) {
            case 'fr':
                return 'Vous envoyez des messages trop vite. Patientez quelques secondes 🙏';
            case 'ar':
                return 'تبعث في رسائل برشة بالزربة. استنى شوية ثواني 🙏';
            default:
                return "You're sending messages too quickly. Please wait a few seconds 🙏";
        }
    }

    // ── Formatting ────────────────────────────────────────────────────────

    public function money(?float $amount, string $lang): string
    {
        if ($amount === null) {
            return '';
        }
        $number = abs($amount - round($amount)) < 0.0005
            ? number_format($amount, 0, '.', '')
            : number_format($amount, 3, '.', '');

        return $number . ($lang === 'ar' ? ' د.ت' : ' DT');
    }

    private function fromWord(string $lang): string
    {
        return ['fr' => 'dès', 'ar' => 'ابتداءً من'][$lang] ?? 'from';
    }

    private function rangeText(array $filters, string $lang): string
    {
        $min = $filters['min_price'] ?? null;
        $max = $filters['max_price'] ?? null;

        if ($min && $max) {
            return [
                'fr' => 'entre ' . $this->money($min, 'fr') . ' et ' . $this->money($max, 'fr'),
                'ar' => 'بين ' . $this->money($min, 'ar') . ' و ' . $this->money($max, 'ar'),
            ][$lang] ?? 'between ' . $this->money($min, 'en') . ' and ' . $this->money($max, 'en');
        }
        if ($max) {
            return [
                'fr' => 'à moins de ' . $this->money($max, 'fr'),
                'ar' => 'بأقل من ' . $this->money($max, 'ar'),
            ][$lang] ?? 'under ' . $this->money($max, 'en');
        }
        if ($min) {
            return [
                'fr' => 'à plus de ' . $this->money($min, 'fr'),
                'ar' => 'بأكثر من ' . $this->money($min, 'ar'),
            ][$lang] ?? 'over ' . $this->money($min, 'en');
        }
        return '';
    }
}
