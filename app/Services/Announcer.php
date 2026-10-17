<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a call into a spoken audio file for the TV.
 *
 * Engines, in order:
 *   1. ElevenLabs (cloud, paid) — when ELEVENLABS_API_KEY is set. Default voice: Kristen.
 *   2. Piper (offline, free)   — when install-voice.bat has been run.
 *   3. Browser voice           — handled by the TV itself when no file exists.
 *
 * Every sentence is generated once and cached in public/tts/. Ticket numbers reset
 * daily, so "F 12 ... DSWD, Window 1" is the same file every day and ElevenLabs
 * credits are only spent the first time a sentence is ever spoken.
 *
 * Audio is generated when the counter calls (prepare()), never while the TV polls,
 * so the single-threaded web server is not held up by the TV.
 */
class Announcer
{
    private const ELEVEN_DOWN_KEY = 'tts:elevenlabs:down';

    /** "Now calling number F 12. Please proceed to D S W D, Window 1." */
    public function textFor(Ticket $ticket): string
    {
        $ticket->loadMissing('service', 'counter');
        $service = $ticket->service?->spoken_name ?: $ticket->service?->name;
        $window = $ticket->counter?->spoken_name ?: $ticket->counter?->name;

        return trim(sprintf(
            'Now calling number %s %d. Please proceed to %s.',
            $ticket->lane->prefix(),
            $ticket->number,
            implode(', ', array_filter([$service, $window]))
        ));
    }

    /** Generate (if needed) and cache the audio for this ticket. Called on call/recall. */
    public function prepare(Ticket $ticket): ?string
    {
        try {
            return $this->audioFor($this->textFor($ticket));
        } catch (\Throwable $e) {
            Log::warning('Announcer failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** Public URL of an already generated file for this text, or null. Never generates. */
    public function cachedAudio(string $text): ?string
    {
        foreach ($this->candidates($text) as [$engine, $file]) {
            if (is_file($this->dir() . DIRECTORY_SEPARATOR . $file)) {
                return '/tts/' . $file;
            }
        }

        return null;
    }

    /** Public URL for this text, generating it with the best available engine. */
    public function audioFor(string $text): ?string
    {
        if (! $this->ensureDir()) {
            return null;
        }

        // Best engine first; a sentence made by Piper during an outage is upgraded
        // to ElevenLabs the next time it is called and the API works again.
        foreach ($this->candidates($text) as [$engine, $file]) {
            $path = $this->dir() . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                return '/tts/' . $file;
            }
            $ok = match ($engine) {
                'elevenlabs' => $this->elevenLabs($text, $path),
                'piper' => $this->piper($text, $path),
                default => false,
            };
            if ($ok) {
                return '/tts/' . $file;
            }
        }

        return null;
    }

    /** Which engine new announcements will use right now. */
    public function engine(): string
    {
        if ($this->elevenLabsReady()) {
            return 'elevenlabs';
        }

        return $this->piperReady() ? 'piper' : 'browser';
    }

    public function elevenLabsConfigured(): bool
    {
        return filled(config('queue_system.tts.elevenlabs.key')) && filled(config('queue_system.tts.elevenlabs.voice'));
    }

    // ------------------------------------------------------------------

    /** @return array<int, array{0:string,1:string}> [engine, filename] in preference order */
    private function candidates(string $text): array
    {
        $list = [];
        if ($this->elevenLabsReady()) {
            $c = config('queue_system.tts.elevenlabs');
            $list[] = ['elevenlabs', 'el-' . substr(sha1("{$c['voice']}|{$c['model']}|{$c['stability']}|{$c['speed']}|{$text}"), 0, 20) . '.mp3'];
        }
        if ($this->piperReady()) {
            $model = (string) config('queue_system.tts.model');
            $list[] = ['piper', 'pp-' . substr(sha1($model . '|' . filesize($model) . '|' . $text), 0, 20) . '.wav'];
        }

        return $list;
    }

    private function elevenLabsReady(): bool
    {
        return $this->elevenLabsConfigured() && function_exists('curl_init') && ! Cache::get(self::ELEVEN_DOWN_KEY);
    }

    private function piperReady(): bool
    {
        return is_file((string) config('queue_system.tts.piper')) && is_file((string) config('queue_system.tts.model'));
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

        // Bad key, no credits or no internet: stop trying for a while and use Piper,
        // so counters are not slowed down by a request that keeps failing.
        if ($status === 0 || $status === 401 || $status === 402 || $status === 429 || $status >= 500
            || str_contains($detail, 'quota') || str_contains($detail, 'credits')) {
            Cache::put(self::ELEVEN_DOWN_KEY, $status ?: 'offline', now()->addMinutes(10));
        }

        return false;
    }

    private function piper(string $text, string $path): bool
    {
        $exe = (string) config('queue_system.tts.piper');
        $model = (string) config('queue_system.tts.model');
        $tmp = $path . '.part.wav';
        $exeDir = dirname($exe);

        $cmd = [$exe, '--model', $model, '--output_file', $tmp];
        if (is_dir($exeDir . DIRECTORY_SEPARATOR . 'espeak-ng-data')) {
            array_push($cmd, '--espeak_data', $exeDir . DIRECTORY_SEPARATOR . 'espeak-ng-data');
        }

        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $exeDir);
        if (! is_resource($proc)) {
            Log::warning('Piper could not be started', ['exe' => $exe]);

            return false;
        }

        fwrite($pipes[0], $text . PHP_EOL);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 || ! is_file($tmp) || filesize($tmp) < 1000) {
            @unlink($tmp);
            Log::warning('Piper failed', ['code' => $code, 'stderr' => mb_substr((string) $stderr, -500)]);

            return false;
        }

        return @rename($tmp, $path) || is_file($path);
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
