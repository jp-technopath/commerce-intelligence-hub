<?php

namespace App\Traits;

trait SanitisesAiJson
{
    /**
     * Clean control characters from a raw JSON string that AI models may emit.
     * Replaces bare newlines/tabs inside string values with their escaped equivalents.
     */
    protected function sanitiseJsonString(string $raw): string
    {
        // Remove NULL bytes and other problematic control chars (keep \t \n \r)
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);

        // Replace literal newlines inside JSON strings with \n
        // We toggle "inside string" by tracking unescaped quotes
        $result    = '';
        $inString  = false;
        $i         = 0;
        $len       = strlen($raw);

        while ($i < $len) {
            $ch = $raw[$i];

            if ($ch === '\\' && $inString) {
                // Skip escape sequence
                $result .= $ch . ($raw[$i + 1] ?? '');
                $i += 2;
                continue;
            }

            if ($ch === '"') {
                $inString = ! $inString;
            }

            if ($inString && $ch === "\n") {
                $result .= '\n';
                $i++;
                continue;
            }

            if ($inString && $ch === "\r") {
                $i++;
                continue;
            }

            if ($inString && $ch === "\t") {
                $result .= '\t';
                $i++;
                continue;
            }

            $result .= $ch;
            $i++;
        }

        return $result;
    }

    /**
     * Extract a JSON object from text that may contain markdown fences
     * or other surrounding content.
     */
    protected function extractJsonFromText(string $text): string
    {
        $clean = trim($text);

        // Strip markdown code fences if present
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```\s*$/i', '', $clean);
        $clean = trim($clean);

        // Find the JSON object (handle any leading/trailing text)
        if (! str_starts_with($clean, '{') && ! str_starts_with($clean, '[')) {
            preg_match('/[\{\[].*[\}\]]/s', $clean, $matches);
            $clean = $matches[0] ?? $clean;
        }

        return $clean;
    }
}
