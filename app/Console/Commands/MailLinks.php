<?php

namespace App\Console\Commands;

use App\Support\EmailLinkAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Render every e-mail with the latest real rows (read-only: rolled back) and
 * list its links; --check requests each one. Nothing is sent.
 */
class MailLinks extends Command
{
    protected $signature = 'mail:links
        {--check : request every link (GET, redirects not followed) and show the HTTP status}
        {--images : include img src}
        {--only= : comma-separated e-mail names}';

    protected $description = 'List (and check) every link in every e-mail the backend sends';

    public function handle(): int
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $rows = [];
        $bad  = 0;
        $statusCache = [];

        foreach ((new EmailLinkAudit())->renderAll() as $name => $r) {
            if ($only && !in_array($name, $only, true)) continue;
            if ($r['html'] === null) {
                $rows[] = [$name, '— ' . $r['error'], '', ''];
                if ($r['error'] !== 'no sample data') $bad++;
                continue;
            }
            foreach (EmailLinkAudit::links($r['html'], (bool) $this->option('images')) as $link) {
                $problem = EmailLinkAudit::problem($link);
                if ($problem) $bad++;
                $status = '';
                if ($this->option('check') && preg_match('#^https?://#i', $link)) {
                    $status = $statusCache[$link] ??= $this->status($link);
                    if (!preg_match('/^(2|3)\d\d/', $status)) $bad++;
                } elseif (str_starts_with($link, 'mailto:')) {
                    $status = 'mailto';
                }
                $rows[] = [$name, $link, $status, $problem ?? 'ok'];
            }
        }

        $this->table(['E-mail', 'Link', 'HTTP', 'Rule'], $rows);
        $this->line($bad ? "<error>{$bad} problem(s)</error>" : '<info>All links OK</info>');
        return $bad ? self::FAILURE : self::SUCCESS;
    }

    private function status(string $url): string
    {
        try {
            $res = Http::withOptions(['allow_redirects' => false])->timeout(15)->get($url);
            $code = (string) $res->status();
            if ($res->redirect()) {
                $code .= ' → ' . mb_substr((string) $res->header('Location'), 0, 80);
            }
            return $code;
        } catch (\Throwable $e) {
            return 'ERR ' . mb_substr($e->getMessage(), 0, 60);
        }
    }
}
