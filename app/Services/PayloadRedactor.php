<?php

namespace App\Services;

class PayloadRedactor
{
    private const SENSITIVE_KEY_PATTERN = '/(?:authorization|cookie|password|secret|token|credential|private[_-]?key|refresh[_-]?token)/i';

    public function redact(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) && strlen($value) > 65536
                ? substr($value, 0, 65536).'[truncated]'
                : $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = $this->redact($item);
        }

        return $redacted;
    }
}
