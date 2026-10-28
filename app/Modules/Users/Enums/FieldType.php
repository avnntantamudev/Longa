<?php

declare(strict_types=1);

namespace App\Modules\Users\Enums;

/**
 * Tipos de campo do formulário dinâmico de uma categoria.
 */
enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Choice = 'choice';
    case Photos = 'photos';
    case Video = 'video';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto',
            self::Number => 'Número',
            self::Choice => 'Escolha',
            self::Photos => 'Fotos',
            self::Video => 'Vídeo',
        };
    }
}
