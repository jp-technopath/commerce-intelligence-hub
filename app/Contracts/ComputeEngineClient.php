<?php

namespace App\Contracts;

use App\ValueObjects\VmTarget;

interface ComputeEngineClient
{
    public function status(VmTarget $target): string;

    public function start(VmTarget $target): ?string;

    public function stop(VmTarget $target): ?string;
}
