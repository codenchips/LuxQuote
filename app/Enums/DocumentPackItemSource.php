<?php

namespace App\Enums;

enum DocumentPackItemSource: string
{
    case Uploaded = 'uploaded';
    case Generated = 'generated';
    case Template = 'template';
}
