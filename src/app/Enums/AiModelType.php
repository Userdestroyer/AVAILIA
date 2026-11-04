<?php

namespace App\Enums;

enum AiModelType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
}
