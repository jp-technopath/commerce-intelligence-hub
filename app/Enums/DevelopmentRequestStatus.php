<?php

namespace App\Enums;

enum DevelopmentRequestStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case StartingVm = 'starting_vm';
    case WaitingForWorker = 'waiting_for_worker';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Queued => 'Queued',
            self::StartingVm => 'Starting VM',
            self::WaitingForWorker => 'Waiting for Worker',
            self::Running => 'Running',
            self::AwaitingApproval => 'Awaiting Approval',
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes Requested',
            self::Rejected => 'Rejected',
            self::Cancelling => 'Cancelling',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
            self::Completed => 'Completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Queued => 'info',
            self::StartingVm => 'warning',
            self::WaitingForWorker => 'warning',
            self::Running => 'primary',
            self::AwaitingApproval => 'warning',
            self::Approved => 'success',
            self::ChangesRequested => 'warning',
            self::Rejected => 'danger',
            self::Cancelling => 'warning',
            self::Cancelled => 'danger',
            self::Failed => 'danger',
            self::Completed => 'success',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-o-document-text',
            self::Queued => 'heroicon-o-queue-list',
            self::StartingVm => 'heroicon-o-cog-6-tooth',
            self::WaitingForWorker => 'heroicon-o-clock',
            self::Running => 'heroicon-o-play-circle',
            self::AwaitingApproval => 'heroicon-o-hand-raised',
            self::Approved => 'heroicon-o-check-circle',
            self::ChangesRequested => 'heroicon-o-pencil',
            self::Rejected => 'heroicon-o-x-circle',
            self::Cancelling => 'heroicon-o-arrow-path',
            self::Cancelled => 'heroicon-o-stop-circle',
            self::Failed => 'heroicon-o-exclamation-circle',
            self::Completed => 'heroicon-o-check-badge',
        };
    }

    /**
     * Check if this state is terminal (no further transitions allowed).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::Cancelled,
            self::Failed,
            self::Completed,
        ]);
    }

    /**
     * Get all valid state values.
     */
    public static function allValues(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }

    /**
     * Get all terminal states.
     */
    public static function terminalValues(): array
    {
        return [
            self::Rejected->value,
            self::Cancelled->value,
            self::Failed->value,
            self::Completed->value,
        ];
    }
}
