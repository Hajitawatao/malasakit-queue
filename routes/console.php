<?php

use App\Enums\Lane;
use App\Models\Counter;
use App\Services\Announcer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

// php artisan voice:test  -> makes one sample announcement and prints the file path
Artisan::command('voice:test', function (Announcer $announcer) {
    Cache::forget('tts:elevenlabs:down'); // retry ElevenLabs right away
    $text = 'Now calling number R 1. Please proceed to D S W D, Window 1.';
    $this->line('Engine: ' . $announcer->engine());
    $url = $announcer->audioFor($text);

    if (! $url) {
        $this->error('No voice file was made. See storage/logs/laravel.log for the reason.');

        return 1;
    }

    $engine = str_starts_with(basename($url), 'el-') ? 'elevenlabs' : 'piper';
    if ($announcer->elevenLabsConfigured() && $engine !== 'elevenlabs') {
        $this->warn('ElevenLabs failed, used Piper instead. See storage/logs/laravel.log for the reason.');
    }
    $this->info('OK (' . $engine . '): ' . public_path(ltrim(str_replace('/', DIRECTORY_SEPARATOR, $url), DIRECTORY_SEPARATOR)));

    return $engine === 'elevenlabs' || ! $announcer->elevenLabsConfigured() ? 0 : 2;
})->purpose('Make one sample TV announcement');

// php artisan voice:warm --to=30 -> pre-makes numbers 1..30 for every lane and window,
// so the first calls of the day are instant. Each new sentence costs ~60 ElevenLabs credits.
Artisan::command('voice:warm {--from=1} {--to=30} {--lanes=fast,priority,regular}', function (Announcer $announcer) {
    $lanes = array_map(fn ($l) => Lane::from(trim($l)), explode(',', $this->option('lanes')));
    $counters = Counter::with('service')->where('is_active', true)->get();
    $made = 0;
    $chars = 0;

    foreach ($counters as $counter) {
        foreach ($lanes as $lane) {
            foreach (range((int) $this->option('from'), (int) $this->option('to')) as $n) {
                $service = $counter->service->spoken_name ?: $counter->service->name;
                $window = $counter->spoken_name ?: $counter->name;
                $text = sprintf('Now calling number %s %d. Please proceed to %s, %s.', $lane->prefix(), $n, $service, $window);

                $cached = $announcer->cachedAudio($text);
                $upgrade = $cached && $announcer->engine() === 'elevenlabs' && ! str_contains($cached, '/el-');
                if ($cached && ! $upgrade) {
                    continue; // already made with the current engine
                }
                if (! $announcer->audioFor($text)) {
                    $this->error("Stopped at {$text} (see storage/logs/laravel.log).");

                    break 3;
                }
                $made++;
                $chars += strlen($text);
            }
        }
    }

    $this->info("Made {$made} new announcements (~{$chars} characters) with " . $announcer->engine() . '.');
})->purpose('Pre-make TV announcements for common ticket numbers');
