<?php

namespace App\Core\Ai\DTOs;

enum AiCapability: string
{
    case Vision = 'vision';
    case Completion = 'completion';
    case TextExtraction = 'text_extraction';
}
