<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseApiController;
use App\Services\Finance\FinancePortalService;
use App\Services\LearnerPortal\LearnerPortalAccessService;
use App\Services\ParentPortal\ParentPortalAccessService;

class FinancePortalController extends BaseApiController
{
    public function __construct(private FinancePortalService $finance, private LearnerPortalAccessService $learnerAccess, private ParentPortalAccessService $parentAccess) {}

    public function learner()
    {
        return $this->success($this->finance->own(auth()->user()));
    }

    public function learnerReceipt(string $payment)
    {
        return $this->success($this->finance->receipt(auth()->user(), $this->learnerAccess->learner(auth()->user()), $payment));
    }

    public function parent(string $learner)
    {
        return $this->success($this->finance->linked(auth()->user(), $learner));
    }

    public function parentReceipt(string $learner, string $payment)
    {
        return $this->success($this->finance->receipt(auth()->user(), $this->parentAccess->requireLinkedLearner(auth()->user(), $learner), $payment));
    }

    public function learnerBenefits()
    {
        return $this->success($this->finance->ownBenefits(auth()->user()));
    }

    public function parentBenefits(string $learner)
    {
        return $this->success($this->finance->linkedBenefits(auth()->user(), $learner));
    }
}
