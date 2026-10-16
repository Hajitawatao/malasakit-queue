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

    // Offline female voice (Piper). Installed by install-voice.bat.
    'tts' => [
        'piper' => env('PIPER_PATH', base_path('tools/piper/piper/' . (PHP_OS_FAMILY === 'Windows' ? 'piper.exe' : 'piper'))),
        'model' => env('PIPER_MODEL', base_path('tools/piper/voice.onnx')),
    ],

    'hospital' => [
        'name' => 'REGION 1 MEDICAL CENTER',
        'address' => 'Arellano Street, Dagupan City, Pangasinan',
        'phone' => '(075) 515-8916',
    ],
];
