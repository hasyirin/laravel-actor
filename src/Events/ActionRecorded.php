<?php

namespace Hasyirin\Actor\Events;

use Hasyirin\Actor\Models\Action;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Queue\SerializesModels;

class ActionRecorded implements ShouldDispatchAfterCommit
{
    use SerializesModels;

    public function __construct(public Action $action) {}
}
