<?php

namespace App\Services;

class UnicodeSearchMatcher
{
    public function allowedEdits(string $query): int
    {
        $length = mb_strlen($query);

        return $length < 5 ? 0 : max(1, intdiv($length, 10));
    }

    /** @return list<string> */
    public function segments(string $query): array
    {
        $count = $this->allowedEdits($query) + 1;
        $length = mb_strlen($query);
        $segments = [];
        for ($index = 0; $index < $count; $index++) {
            $start = intdiv($index * $length, $count);
            $segments[] = mb_substr($query, $start, intdiv(($index + 1) * $length, $count) - $start);
        }

        return $segments;
    }

    public function matches(string $query, string $text, bool $substring = false): bool
    {
        $query = mb_strtolower($query);
        $text = mb_strtolower($text);
        $limit = $this->allowedEdits($query);
        $letters = mb_str_split($query);
        $length = count($letters);
        $text = mb_str_split($substring ? $text : mb_substr($text, 0, $length + $limit));
        $previous = range(0, $length);
        foreach ($text as $index => $letter) {
            $current = [$substring ? 0 : min($index + 1, $limit + 1)];
            for ($position = 1; $position <= $length; $position++) {
                $current[$position] = min($limit + 1, $previous[$position] + 1, $current[$position - 1] + 1, $previous[$position - 1] + ($letters[$position - 1] === $letter ? 0 : 1));
            }
            if ($current[$length] <= $limit) {
                return true;
            }
            if (! $substring && min($current) > $limit) {
                return false;
            }
            $previous = $current;
        }

        return false;
    }
}
