<?php

namespace App\Services\Ai;

/**
 * Text clean-up shared by the AI services: prompts get single-line, length-limited
 * fields; model output is cleaned before it is stored or returned.
 */
trait AiText
{
    /**
     * Single-line text for prompts: control characters and whitespace runs collapse to one space.
     */
    private function clip(?string $value, int $max): string
    {
        $value = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', (string) $value));

        return mb_substr($value, 0, $max);
    }

    /**
     * Model output keeps its line breaks (bullet points) but loses control characters.
     */
    private function clipLines(string $value, int $max): string
    {
        $value = preg_replace('/[^\P{C}\n]+/u', '', str_replace(["\r\n", "\r"], "\n", $value));
        $value = trim((string) preg_replace('/\n{3,}/', "\n\n", (string) $value));

        return mb_substr($value, 0, $max);
    }

    /**
     * A model value as clean single-line text; anything that is not a string becomes ''.
     */
    private function textOf(mixed $value, int $max): string
    {
        return is_string($value) ? $this->clip($value, $max) : '';
    }
}
