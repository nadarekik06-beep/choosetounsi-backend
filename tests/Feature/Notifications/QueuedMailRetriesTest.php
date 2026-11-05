<?php

namespace Tests\Feature\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every queued e-mail (queued notification with a mail channel, queued
 * mailable) survives a short SMTP / DNS outage: at least 5 tries with a
 * backoff of at least an hour in total (App\Support\Queue\RetriesDelivery or
 * the same schedule written by hand). Before this, some had 1 try, others 3
 * immediate tries, so a few minutes without DNS lost the e-mail.
 */
class QueuedMailRetriesTest extends TestCase
{
    public function test_every_queued_email_retries_with_backoff(): void
    {
        $checked = 0;
        foreach (['Notifications', 'Mail'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path($dir)));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') continue;
                $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path() . DIRECTORY_SEPARATOR));
                $class = str_replace('\\\\', '\\', str_replace(DIRECTORY_SEPARATOR, '\\', $class));
                if (!class_exists($class)) continue;
                $ref = new \ReflectionClass($class);
                if ($ref->isAbstract() || !$ref->implementsInterface(ShouldQueue::class)) continue;
                $isMail = $ref->isSubclassOf(Mailable::class)
                    || ($ref->isSubclassOf(Notification::class) && (
                        $ref->hasMethod('toMail') && $ref->getMethod('toMail')->class !== Notification::class));
                if (!$isMail) continue;

                $tries = $ref->getDefaultProperties()['tries'] ?? null;
                $this->assertGreaterThanOrEqual(5, (int) $tries, "{$class}: \$tries");
                $this->assertTrue($ref->hasMethod('backoff'), "{$class}: backoff()");
                $backoff = $ref->newInstanceWithoutConstructor()->backoff();
                $this->assertIsArray($backoff, "{$class}: backoff() array");
                $this->assertGreaterThanOrEqual(3600, array_sum($backoff), "{$class}: backoff total");
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(15, $checked, 'queued e-mail classes found');
    }
}
