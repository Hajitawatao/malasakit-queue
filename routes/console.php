<?php

use App\Enums\Lane;
use App\Enums\TicketStatus;
use App\Models\Counter;
use App\Models\Ticket;
use App\Services\Announcer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * php artisan voice:worker
 * Runs all day in its own window (start.bat starts it). Makes the TV's voice files
 * in the background so counters and the TV never wait:
 *   1. tickets just called that still have no voice file (first),
 *   2. the next few WAITING tickets of every lane, for every window of their service,
 *      so the file is usually ready before the counter even presses Call Next.
 */
Artisan::command('voice:worker {--ahead=3 : waiting tickets to prepare per lane}', function (Announcer $announcer) {
    $this->info('Voice worker started. Engine: ' . $announcer->engine() . '. Leave this window open.');
    $ahead = max(1, (int) $this->option('ahead'));
    $lastEngine = null;

    while (true) {
        try {
            $engine = $announcer->engine();
            if ($engine !== $lastEngine) {
                $this->line('[' . now()->format('H:i:s') . "] Engine: {$engine}");
                $lastEngine = $engine;
            }
            if ($engine === 'browser') {
                sleep(10); // nothing installed; the TV uses the browser voice

                continue;
            }

            $todo = [];

            // 1. Just called, no file yet
            $called = Ticket::today()
                ->whereIn('status', TicketStatus::active())
                ->where('called_at', '>=', now()->subMinutes(2))
                ->with('service', 'counter')
                ->orderByDesc('called_at')
                ->get();
            foreach ($called as $t) {
                $todo[] = $announcer->textFor($t);
            }

            // 2. Next waiting tickets, for every active window of their service
            $counters = Counter::with('service')->where('is_active', true)->get()->groupBy('service_id');
            foreach (Lane::cases() as $lane) {
                $next = Ticket::today()
                    ->where('status', TicketStatus::Waiting)
                    ->where('lane', $lane->value)
                    ->orderBy('id')
                    ->get()
                    ->groupBy('service_id');
                foreach ($next as $serviceId => $tickets) {
                    foreach ($tickets->take($ahead) as $t) {
                        foreach ($counters->get($serviceId, collect()) as $counter) {
                            $todo[] = $announcer->textForWaiting($t, $counter);
                        }
                    }
                }
            }

            $made = false;
            foreach (array_unique($todo) as $text) {
                if ($announcer->hasPreferred($text)) {
                    continue;
                }
                $start = microtime(true);
                $url = $announcer->audioFor($text);
                $secs = round(microtime(true) - $start, 1);
                $this->line('[' . now()->format('H:i:s') . '] ' . ($url ? 'Made' : 'FAILED') . " ({$secs}s): {$text}");
                $made = true;
                break; // re-check: a new call may have come in meanwhile
            }

            if (! $made) {
                sleep(1);
            }
        } catch (\Throwable $e) {
            $this->error('[' . now()->format('H:i:s') . '] ' . $e->getMessage());
            try {
                DB::reconnect();
            } catch (\Throwable) {
            }
            sleep(3);
        }
    }
})->purpose('Make TV voice files in the background (keep running)');

// php artisan voice:test  -> makes one sample announcement and prints the file path
Artisan::command('voice:test', function (Announcer $announcer) {
    Cache::forget('tts:elevenlabs:down'); // retry ElevenLabs right away
    $text = 'Now calling number R 1. Please proceed to D S W D, Window 1.';
    $engine = $announcer->engine();
    $this->line('Engine: ' . $engine);
    $url = $announcer->audioFor($text);

    if (! $url) {
        $this->error('No voice file was made. See storage/logs/laravel.log for the reason.');

        return 1;
    }

    $used = match (substr(basename($url), 0, 3)) {
        'el-' => 'elevenlabs', 'kk-' => 'kokoro', 'pp-' => 'piper', default => 'unknown',
    };
    if ($used !== $engine) {
        $this->warn("{$engine} failed, used {$used} instead. See storage/logs/laravel.log for the reason.");
    }
    $this->info('OK (' . $used . '): ' . public_path(ltrim(str_replace('/', DIRECTORY_SEPARATOR, $url), DIRECTORY_SEPARATOR)));

    return $used === $engine ? 0 : 2;
})->purpose('Make one sample TV announcement');

// php artisan voice:warm --to=30 -> pre-makes numbers 1..30 for every lane and window,
// so the first calls of the day are instant. Free with Kokoro/Piper; ~60 credits each with ElevenLabs.
Artisan::command('voice:warm {--from=1} {--to=30} {--lanes=fast,priority,regular}', function (Announcer $announcer) {
    $lanes = array_map(fn ($l) => Lane::from(trim($l)), explode(',', $this->option('lanes')));
    $counters = Counter::with('service')->where('is_active', true)->get();
    $made = 0;
    $this->info('Engine: ' . $announcer->engine());

    foreach (range((int) $this->option('from'), (int) $this->option('to')) as $n) {
        foreach ($lanes as $lane) {
            foreach ($counters as $counter) {
                $text = $announcer->sentence($lane, $n,
                    $counter->service->spoken_name ?: $counter->service->name, $counter->spoken_name ?: $counter->name);
                if ($announcer->hasPreferred($text)) {
                    continue;
                }
                if (! $announcer->audioFor($text)) {
                    $this->error("Stopped at: {$text} (see storage/logs/laravel.log).");

                    return 1;
                }
                $made++;
                $this->line("[{$made}] {$text}");
            }
        }
    }

    $this->info("Done. Made {$made} new announcements with " . $announcer->engine() . '.');
})->purpose('Pre-make TV announcements for common ticket numbers');
