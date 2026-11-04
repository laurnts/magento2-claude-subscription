<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\ClaudeSubscription\Setup;

/**
 * Rewrites the provider key inside a saved AI services document.
 */
class ServiceCodeRenamer
{
    /**
     * Provider code stored before this rename.
     */
    public const FROM = 'claude_max';

    /**
     * Provider code stored after this rename.
     */
    public const TO = 'claude_subscription';

    /**
     * Renamed JSON, or null when the document must be left on disk as it is.
     *
     * @param string $raw
     * @return string|null
     */
    public function rename(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }
        $changed = false;
        foreach ($decoded as $rowId => $row) {
            if (!is_array($row) || array_key_exists(self::TO, $row)) {
                continue;
            }
            $rebuilt = [];
            $rowChanged = false;
            foreach ($row as $key => $value) {
                if ($key === self::FROM) {
                    $rebuilt[self::TO] = $value;
                    $rowChanged = true;
                    continue;
                }
                $rebuilt[$key] = $value;
            }
            if ($rowChanged) {
                $decoded[$rowId] = $rebuilt;
                $changed = true;
            }
        }
        if (!$changed) {
            return null;
        }
        $encoded = json_encode($decoded);
        if ($encoded === false) {
            return null;
        }
        return $encoded;
    }
}
