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

    // TV voice. ElevenLabs is used when ELEVENLABS_API_KEY is set (run setup-elevenlabs.bat);
    // otherwise Piper (offline, install-voice.bat); otherwise the browser voice.
    'tts' => [
        'elevenlabs' => [
            'key' => env('ELEVENLABS_API_KEY'),
            'voice' => env('ELEVENLABS_VOICE_ID', 'dfeOmy6Uay63tNhyO99j'), // Kristen (female, US English)
            'model' => env('ELEVENLABS_MODEL', 'eleven_multilingual_v2'),
            'stability' => env('ELEVENLABS_STABILITY', 0.8),  // high = steady, straight read
            'similarity' => env('ELEVENLABS_SIMILARITY', 0.8),
            'speed' => env('ELEVENLABS_SPEED', 1.0),
            'timeout' => env('ELEVENLABS_TIMEOUT', 8),
            'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
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
