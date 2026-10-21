<?php

namespace Webkul\Ondc\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class OndcHelper
{
    /**
     * Return an ONDC-compliant timestamp string.
     *
     * Exactly 3 fractional digits: YYYY-MM-DDTHH:MM:SS.mmmZ
     * Guaranteed to be greater than any preceding inbound or cached timestamp.
     */
    public static function timestamp(?string $inboundTimestamp = null): string
    {
        $nowMs = now()->utc()->getTimestampMs();

        // 1. If an inbound timestamp was provided, ensure we are ahead of it
        if ($inboundTimestamp) {
            try {
                $inboundMs = Carbon::parse($inboundTimestamp)->utc()->getTimestampMs();
                if ($nowMs <= $inboundMs) {
                    $nowMs = $inboundMs + 1000; // Force 1 second ahead of incoming
                }
            } catch (\Throwable $e) {
                // fallback to current time if parsing fails
            }
        }

        // 2. Ensure we are ahead of the globally cached previous timestamp across PHP processes
        $lastMs = Cache::get('ondc:last_global_timestamp_ms', 0);
        if ($nowMs <= $lastMs) {
            $nowMs = $lastMs + 1000;
        }

        // 3. Save new high watermark (valid for 1 hour)
        Cache::put('ondc:last_global_timestamp_ms', $nowMs, now()->addHour());

        return Carbon::createFromTimestampMs($nowMs, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }
}