<?php

namespace App\Services\PM;

use App\Models\PmConnection;
use App\Models\PmProject;
use App\Models\PmWorkItem;
use App\Models\User;

interface ProjectManagementProvider
{
    public function syncProjects(PmConnection $connection): array;

    public function syncWorkItems(PmProject $project): array;

    public function syncWorklogs(PmWorkItem $workItem): array;

    public function getWorkItem(PmConnection $connection, string $externalItemId, ?User $actor = null): array;

    public function addComment(PmWorkItem $workItem, string $comment, ?User $actor = null): bool;

    public function transitionWorkItem(PmWorkItem $workItem, string $semanticAction, ?User $actor = null): bool;

    public function updateEstimate(PmWorkItem $workItem, int $estimatedSeconds, ?User $actor = null): bool;
}
