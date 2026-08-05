<?php
namespace App\Core;

/**
 * Audit trail. Never let logging break the request that triggered it.
 */
class ActivityLog
{
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $description = null
    ): void {
        try {
            Database::insert('activity_log', [
                'user_id'     => Auth::id(),
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'description' => $description === null ? null : mb_substr($description, 0, 400),
                'ip_address'  => self::ip(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Activity log write failed: ' . $e->getMessage());
        }
    }

    private static function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
