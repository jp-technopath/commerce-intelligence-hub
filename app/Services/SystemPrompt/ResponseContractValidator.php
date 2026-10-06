<?php

namespace App\Services\SystemPrompt;

class ResponseContractValidator
{
    /**
     * Validate an AI response array against the application contract for the given prompt key.
     *
     * @param string $key
     * @param array $data
     * @return array{valid: bool, errors: array<string>}
     */
    public function validate(string $key, array $data): array
    {
        $errors = [];

        match ($key) {
            'meeting_prep' => $this->validateMeetingPrep($data, $errors),
            'meeting_followup' => $this->validateMeetingFollowUp($data, $errors),
            default => null,
        };

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    protected function validateMeetingPrep(array $data, array &$errors): void
    {
        $requiredKeys = [
            'internal_summary',
            'customer_email_subject',
            'customer_email_body',
            'recommended_agenda',
        ];

        foreach ($requiredKeys as $k) {
            if (! array_key_exists($k, $data)) {
                $errors[] = "Missing required response key: '{$k}'.";
            } elseif (! is_string($data[$k])) {
                $errors[] = "Field '{$k}' must be a string.";
            }
        }
    }

    protected function validateMeetingFollowUp(array $data, array &$errors): void
    {
        $requiredKeys = [
            'summary',
            'followup_email_subject',
            'followup_email_body',
            'decisions',
            'open_questions',
            'suggested_action_items',
        ];

        foreach ($requiredKeys as $k) {
            if (! array_key_exists($k, $data)) {
                $errors[] = "Missing required response key: '{$k}'.";
            }
        }

        if (isset($data['suggested_action_items'])) {
            if (! is_array($data['suggested_action_items'])) {
                $errors[] = "Field 'suggested_action_items' must be an array.";
            } else {
                foreach ($data['suggested_action_items'] as $index => $item) {
                    if (! is_array($item)) {
                        $errors[] = "Action item at index {$index} must be an object.";
                        continue;
                    }
                    if (empty($item['title']) || ! is_string($item['title'])) {
                        $errors[] = "Action item at index {$index} is missing a valid 'title'.";
                    }
                    // owner_name and due_date must be nullable strings or null
                    if (isset($item['owner_name']) && ! is_string($item['owner_name']) && ! is_null($item['owner_name'])) {
                        $errors[] = "Action item at index {$index} 'owner_name' must be a string or null.";
                    }
                    if (isset($item['due_date']) && ! is_string($item['due_date']) && ! is_null($item['due_date'])) {
                        $errors[] = "Action item at index {$index} 'due_date' must be a string or null.";
                    }
                }
            }
        }
    }

    /**
     * Sanitizes and normalizes suggested action items, converting empty/placeholder strings to null.
     */
    public function sanitizeActionItems(array $rawItems): array
    {
        $sanitized = [];

        foreach ($rawItems as $item) {
            if (! is_array($item) || empty($item['title'])) {
                continue;
            }

            $owner = isset($item['owner_name']) && is_string($item['owner_name']) ? trim($item['owner_name']) : null;
            if ($owner === '' || strtolower($owner) === 'null' || strtolower($owner) === 'tbd' || strtolower($owner) === 'unassigned') {
                $owner = null;
            }

            $due = isset($item['due_date']) && is_string($item['due_date']) ? trim($item['due_date']) : null;
            if ($due === '' || strtolower($due) === 'null' || strtolower($due) === 'tbd') {
                $due = null;
            }

            $sanitized[] = [
                'title'              => trim($item['title']),
                'owner_name'         => $owner,
                'due_date'           => $due,
                'is_customer_facing' => ! empty($item['is_customer_facing']),
            ];
        }

        return $sanitized;
    }
}
