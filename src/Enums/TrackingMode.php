<?php

namespace LaravelCloudTracker\Enums;

enum TrackingMode: string
{
    case ALL = 'all';
    case NONE = 'none';
    case ALLOWLIST = 'allowlist';
    case DENYLIST = 'denylist';

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'Track All Features',
            self::NONE => 'Track Nothing',
            self::ALLOWLIST => 'Allowlist Only',
            self::DENYLIST => 'Denylist (Exclude Listed)',
        };
    }
}
