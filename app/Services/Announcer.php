<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Facades\Log;

/**
 * Turns a call into a spoken WAV file using Piper (free, offline neural TTS).
 *
 * Piper is installed by install-voice.bat into tools/piper/. When it is not
 * installed, audioFor() returns null and the TV falls back to the browser voice.
 * Files are cached in public/tts/, so a recall plays the same file instantly.
 */
class Announcer
{
    public function available(): bool
    {
        return is_file($this->exe()) && is_file($this->model());
    }

    /** "Now calling number F 12. Please proceed to D S W D, Window 1." */
    public function textFor(Ticket $ticket): string
    {
        $service = $ticket->service?->spoken_name ?: $ticket->service?->name;
        $window = $ticket->counter?->spoken_name ?: $ticket->counter?->name;

        return trim(sprintf(
            'Now calling number %s %d. Please proceed to %s.',
            $ticket->lane->prefix(),
            $ticket->number,
            implode(', ', array_filter([$service, $window]))
        ));
    }

    /** Public URL of the WAV for this text, or null if Piper is unavailable/failed. */
    public function audioFor(string $text): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $dir = public_path('tts');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }

        $name = substr(sha1($this->model() . '|' . filesize($this->model()) . '|' . $text), 0, 20) . '.wav';
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        if (! is_file($path) && ! $this->synthesize($text, $path)) {
            return null;
        }

        return '/tts/' . $name;
    }

    private function synthesize(string $text, string $path): bool
    {
        $tmp = $path . '.part.wav';
        $exeDir = dirname($this->exe());

        $cmd = [$this->exe(), '--model', $this->model(), '--output_file', $tmp];
        if (is_dir($exeDir . DIRECTORY_SEPARATOR . 'espeak-ng-data')) {
            array_push($cmd, '--espeak_data', $exeDir . DIRECTORY_SEPARATOR . 'espeak-ng-data');
        }

        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $exeDir);
        if (! is_resource($proc)) {
            Log::warning('Piper could not be started', ['exe' => $this->exe()]);

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

    private function exe(): string
    {
        return (string) config('queue_system.tts.piper');
    }

    private function model(): string
    {
        return (string) config('queue_system.tts.model');
    }
}
