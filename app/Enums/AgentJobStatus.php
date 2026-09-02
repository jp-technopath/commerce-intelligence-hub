<?php

namespace App\Enums;

enum AgentJobStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Running = 'running';
    case ResultReceived = 'result_received';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Completed = 'completed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Failed, self::Completed], true);
    }
}
