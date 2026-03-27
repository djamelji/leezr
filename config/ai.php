<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Driver
    |--------------------------------------------------------------------------
    |
    | The default AI provider. Set to 'null' to disable AI processing.
    | Available: 'null', 'ollama', 'openai', 'anthropic'
    |
    */

    'driver' => env('AI_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Ollama Configuration
    |--------------------------------------------------------------------------
    */

    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3'),
        'vision_model' => env('OLLAMA_VISION_MODEL', 'llava'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic Configuration
    |--------------------------------------------------------------------------
    */

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
    ],

];
