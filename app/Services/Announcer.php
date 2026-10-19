<?php

namespace App\Services;

use App\Enums\Lane;
use App\Models\Counter;
use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a call into a spoken audio file for the TV.
 *
 * Engines, best first (the first one that is set up is used):
 *   1. ElevenLabs (cloud, paid)  — when ELEVENLABS_API_KEY is set (setup-elevenlabs.bat).
 *   2. Kokoro (offline, free)    — when install-kokoro.bat has been run. Default voice af_heart.
 *   3. Piper (offline, free)     — when install-voice.bat has been run.
 *   4. Browser voice             — handled by the TV itself when no file exists.
 *
 * Every sentence is generated once and cached in public/tts/. Ticket numbers reset
 * daily, so "F, zero, one, two ... DSWD, Window 1" is the same file every day.
 *
 * Files are made by the voice worker (php artisan voice:worker, started by start.bat),
 * never inside a web request, so the single-threaded web server never waits for audio.
 */
class Announcer
{
    private const ELEVEN_DOWN_KEY = 'tts:elevenlabs:down';

    /** Kokoro v1.0 speaker ids (read from the model's metadata). */
    private const KOKORO_VOICES = [
        'af_alloy' => 0, 'af_aoede' => 1, 'af_bella' => 2, 'af_heart' => 3, 'af_jessica' => 4,
        'af_kore' => 5, 'af_nicole' => 6, 'af_nova' => 7, 'af_river' => 8, 'af_sarah' => 9,
        'af_sky' => 10, 'bf_alice' => 20, 'bf_emma' => 21, 'bf_isabella' => 22, 'bf_lily' => 23,
    ];

    // ---- what is said ----------------------------------------------------

    /** "Now calling number, P, zero, zero, one. Please proceed to D S W D, Window 1." */
    public function textFor(Ticket $ticket): string
    {
        $ticket->loadMissing('service', 'counter');

        return $this->sentence($ticket->lane, $ticket->number, $ticket->service?->spoken_name ?: $ticket->service?->name,
            $ticket->counter?->spoken_name ?: $ticket->counter?->name);
    }

    /** Same sentence for a ticket that is still waiting, as if called at $counter. */
    public function textForWaiting(Ticket $ticket, Counter $counter): string
    {
        $counter->loadMissing('service');

        return $this->sentence($ticket->lane, $ticket->number, $counter->service?->spoken_name ?: $counter->service?->name,
            $counter->spoken_name ?: $counter->name);
    }

    public function sentence(Lane $lane, int $number, ?string $service, ?string $window): string
    {
        return trim(sprintf(
            'Now calling number, %s. Please proceed to %s.',
            $this->spokenCode($lane->prefix(), $number),
            implode(', ', array_filter([$service, $window]))
        ));
    }

    /**
     * Ticket code read one character at a time, exactly as printed:
     * P001 -> "P, zero, zero, one" (commas make the voice pause between them).
     */
    public function spokenCode(string $prefix, int $number): string
    {
        $words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];
        $digits = str_pad((string) $number, (int) config('queue_system.number_padding', 3), '0', STR_PAD_LEFT);

        return implode(', ', array_merge([$prefix], array_map(fn ($d) => $words[(int) $d], str_split($digits))));
    }

    // ---- files -----------------------------------------------------------

    /** Public URL of an already made file for this text (best engine first), or null. Never generates. */
    public function cachedAudio(string $text): ?string
    {
        foreach ($this->candidates($text) as [, $file]) {
            if (is_file($this->path($file))) {
                return '/tts/' . $file;
            }
        }

        return null;
    }

    /** True when this text already has a file from the best engine available right now. */
    public function hasPreferred(string $text): bool
    {
        $first = $this->candidates($text)[0] ?? null;

        return $first && is_file($this->path($first[1]));
    }

    /** Public URL for this text, generating it with the best engine that works. */
    public function audioFor(string $text): ?string
    {
        if (! $this->ensureDir()) {
            return null;
        }

        // Best engine first. A sentence made by a lower engine (e.g. during an outage)
        // is upgraded the next time it is requested and the better engine works.
        foreach ($this->candidates($text) as [$engine, $file]) {
            $path = $this->path($file);
            if (is_file($path)) {
                return '/tts/' . $file;
            }
            try {
                $ok = match ($engine) {
                    'elevenlabs' => $this->elevenLabs($text, $path),
                    'kokoro' => $this->kokoro($text, $path),
                    'piper' => $this->piper($text, $path),
                    default => false,
                };
            } catch (\Throwable $e) {
                Log::warning("TTS {$engine} crashed", ['error' => $e->getMessage()]);
                $ok = false;
            }
            if ($ok) {
                return '/tts/' . $file;
            }
        }

        return null;
    }

    /** Which engine new announcements will use right now. */
    public function engine(): string
    {
        return $this->engines()[0] ?? 'browser';
    }

    public function elevenLabsConfigured(): bool
    {
        return filled(config('queue_system.tts.elevenlabs.key')) && filled(config('queue_system.tts.elevenlabs.voice'));
    }

    // ------------------------------------------------------------------

    /** @return string[] engines that are set up, best first */
    private function engines(): array
    {
        $list = [];
        if ($this->elevenLabsReady()) {
            $list[] = 'elevenlabs';
        }
        if ($this->kokoroReady()) {
            $list[] = 'kokoro';
        }
        if ($this->piperReady()) {
            $list[] = 'piper';
        }

        return $list;
    }

    /** @return array<int, array{0:string,1:string}> [engine, filename] in preference order */
    private function candidates(string $text): array
    {
        $list = [];
        foreach ($this->engines() as $engine) {
            $list[] = [$engine, match ($engine) {
                'elevenlabs' => 'el-' . $this->hash(config('queue_system.tts.elevenlabs'), ['key', 'base_url', 'timeout'], $text) . '.mp3',
                'kokoro' => 'kk-' . $this->hash(config('queue_system.tts.kokoro'), ['exe', 'model_dir', 'threads'], $text) . '.wav',
                'piper' => 'pp-' . substr(sha1(config('queue_system.tts.model') . '|' . @filesize(config('queue_system.tts.model')) . '|' . $text), 0, 20) . '.wav',
            }];
        }

        return $list;
    }

    private function hash(array $config, array $ignore, string $text): string
    {
        $parts = array_diff_key($config, array_flip($ignore));
        ksort($parts);

        return substr(sha1(json_encode($parts) . '|' . $text), 0, 20);
    }

    private function elevenLabsReady(): bool
    {
        return $this->elevenLabsConfigured() && function_exists('curl_init') && ! Cache::get(self::ELEVEN_DOWN_KEY);
    }

    private function kokoroReady(): bool
    {
        $c = config('queue_system.tts.kokoro');

        return is_file($c['exe']) && is_file($c['model_dir'] . '/model.int8.onnx') && is_file($c['model_dir'] . '/voices.bin');
    }

    private function piperReady(): bool
    {
        return is_file((string) config('queue_system.tts.piper')) && is_file((string) config('queue_system.tts.model'));
    }

    // ---- engines ---------------------------------------------------------

    private function kokoro(string $text, string $path): bool
    {
        $c = config('queue_system.tts.kokoro');
        $m = rtrim($c['model_dir'], '/\\');
        $sid = self::KOKORO_VOICES[$c['voice']] ?? (is_numeric($c['voice']) ? (int) $c['voice'] : 3);
        $tmp = $path . '.part.wav';
        $lexicon = str_starts_with((string) $c['voice'], 'b') ? 'lexicon-gb-en.txt' : 'lexicon-us-en.txt'; // British voices

        $cmd = [
            $c['exe'],
            '--kokoro-model=' . $m . DIRECTORY_SEPARATOR . 'model.int8.onnx',
            '--kokoro-voices=' . $m . DIRECTORY_SEPARATOR . 'voices.bin',
            '--kokoro-tokens=' . $m . DIRECTORY_SEPARATOR . 'tokens.txt',
            '--kokoro-data-dir=' . $m . DIRECTORY_SEPARATOR . 'espeak-ng-data',
            '--kokoro-lexicon=' . $m . DIRECTORY_SEPARATOR . $lexicon,
            '--kokoro-lang=en',
            '--num-threads=' . max(1, (int) $c['threads']),
            '--sid=' . $sid,
            '--kokoro-length-scale=' . (1 / max(0.5, (float) $c['speed'])),
            '--output-filename=' . $tmp,
            $text,
        ];
        return $this->run('Kokoro', $cmd, dirname($c['exe']), null, $tmp, $path);
    }

    private function piper(string $text, string $path): bool
    {
        $exe = (string) config('queue_system.tts.piper');
        $model = (string) config('queue_system.tts.model');
        $tmp = $path . '.part.wav';

        $cmd = [$exe, '--model', $model, '--output_file', $tmp, '--length_scale', '1.15']; // a bit slower
        $espeak = dirname($exe) . DIRECTORY_SEPARATOR . 'espeak-ng-data';
        if (is_dir($espeak)) {
            array_push($cmd, '--espeak_data', $espeak);
        }

        return $this->run('Piper', $cmd, dirname($exe), $text . PHP_EOL, $tmp, $path);
    }

    /** Runs a TTS program that writes $tmp, then moves it to $path. */
    private function run(string $name, array $cmd, string $cwd, ?string $stdin, string $tmp, string $path): bool
    {
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($proc)) {
            Log::warning("{$name} could not be started", ['exe' => $cmd[0]]);

            return false;
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 || ! is_file($tmp) || filesize($tmp) < 1000) {
            @unlink($tmp);
            Log::warning("{$name} failed", ['code' => $code, 'stderr' => mb_substr((string) $stderr, -600)]);

            return false;
        }

        return @rename($tmp, $path) || is_file($path);
    }

    private function elevenLabs(string $text, string $path): bool
    {
        $c = config('queue_system.tts.elevenlabs');
        $url = rtrim($c['base_url'], '/') . '/v1/text-to-speech/' . rawurlencode($c['voice']) . '?output_format=mp3_44100_128';

        $settings = [
            'stability' => (float) $c['stability'],        // high = steady, straight read
            'similarity_boost' => (float) $c['similarity'],
            'style' => 0.0,                                // no acting / exaggeration
            'use_speaker_boost' => true,
        ];
        if ((float) $c['speed'] !== 1.0) {
            $settings['speed'] = (float) $c['speed'];
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => (int) $c['timeout'],
            CURLOPT_HTTPHEADER => [
                'xi-api-key: ' . $c['key'],
                'Content-Type: application/json',
                'Accept: audio/mpeg',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'text' => $text,
                'model_id' => $c['model'],
                'voice_settings' => $settings,
            ]),
        ];
        $ca = base_path('tools/cacert.pem');
        if (is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca; // XAMPP's PHP often has no CA bundle configured
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $status === 200 && str_contains($type, 'audio') && strlen($body) > 1000) {
            file_put_contents($path . '.part', $body);

            return @rename($path . '.part', $path) || is_file($path);
        }

        $detail = $error ?: mb_substr(is_string($body) ? $body : '', 0, 300);
        Log::warning('ElevenLabs failed', ['status' => $status, 'detail' => $detail]);

        // Bad key, no credits or no internet: stop trying for a while and use the next engine.
        if ($status === 0 || $status === 401 || $status === 402 || $status === 429 || $status >= 500
            || str_contains($detail, 'quota') || str_contains($detail, 'credits')) {
            Cache::put(self::ELEVEN_DOWN_KEY, $status ?: 'offline', now()->addMinutes(10));
        }

        return false;
    }

    // ---- helpers ---------------------------------------------------------

    private function path(string $file): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . $file;
    }

    private function dir(): string
    {
        return public_path('tts');
    }

    private function ensureDir(): bool
    {
        $dir = $this->dir();

        return is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir);
    }
}
