<?php

return [
    // Weighted round-robin ng "Call Next".
    // Halimbawa: 2 fast, 1 priority, 1 regular kada ikot.
    // Kapag puro fast/priority lang ang tinatawag, maiipit nang buong araw ang regular.
    'weights' => [
        'fast' => (int) env('QUEUE_WEIGHT_FAST', 2),
        'priority' => (int) env('QUEUE_WEIGHT_PRIORITY', 1),
        'regular' => (int) env('QUEUE_WEIGHT_REGULAR', 1),
    ],

    'number_padding' => 3,          // F001
    'max_recalls' => 3,             // pagkatapos nito, i-mark as no-show
    'timezone' => 'Asia/Manila',

    // TV voice, best available first: ElevenLabs (if ELEVENLABS_API_KEY is set),
    // Kokoro (install-kokoro.bat), Piper (install-voice.bat), then the browser voice.
    'tts' => [
        'elevenlabs' => [
            'key' => env('ELEVENLABS_API_KEY'),
            'voice' => env('ELEVENLABS_VOICE_ID', 'dfeOmy6Uay63tNhyO99j'), // Kristen (female, US English)
            'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
            'stability' => env('ELEVENLABS_STABILITY', 0.8),  // high = steady, straight read
            'similarity' => env('ELEVENLABS_SIMILARITY', 0.8),
            'speed' => env('ELEVENLABS_SPEED', 0.9),
            'timeout' => env('ELEVENLABS_TIMEOUT', 8),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
        ],
        // Kokoro (offline neural voice) via sherpa-onnx. Installed by install-kokoro.bat.
        'kokoro' => [
            'exe' => env('KOKORO_EXE', base_path('tools/kokoro/bin/sherpa-onnx-offline-tts' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))),
            'model_dir' => env('KOKORO_MODEL_DIR', base_path('tools/kokoro/model')),
            'voice' => env('KOKORO_VOICE', 'af_heart'), // female: af_heart, af_bella, af_sarah, af_nicole, bf_emma ...
            'speed' => env('KOKORO_SPEED', 0.85),       // 1.0 = normal; lower = slower (0.85 is clear for a hall)
            'threads' => env('KOKORO_THREADS', 4),
        ],
        'piper' => env('PIPER_PATH', base_path('tools/piper/piper/' . (PHP_OS_FAMILY === 'Windows' ? 'piper.exe' : 'piper'))),
        'model' => env('PIPER_MODEL', base_path('tools/piper/voice.onnx')),
    ],

    'hospital' => [
        'name' => 'REGION 1 MEDICAL CENTER',
        'address' => 'Arellano Street, Dagupan City, Pangasinan',
        'phone' => '(075) 515-8916',
    ],
];
