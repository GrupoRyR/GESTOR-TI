<?php

namespace App\Actions\Ai;

use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "+1 Es lo mismo": one vote per person (idempotent), removable. Each vote
 * raises the impact (PriorityCalculator::VOTE_WEIGHT), so the counter and
 * the suggested priority are recomputed from the votes table every time.
 */
class VoteRequest
{
    public function vote(AiRequest $request, User $user): AiRequest
    {
        Gate::forUser($user)->authorize('vote', $request);

        return DB::transaction(function () use ($request, $user): AiRequest {
            $request->votes()->firstOrCreate(['user_id' => $user->id]);
            // Voters follow it; removing the vote keeps following ("Siguiendo" toggles it).
            $request->followers()->syncWithoutDetaching([$user->id]);

            return $this->refresh($request);
        });
    }

    public function unvote(AiRequest $request, User $user): AiRequest
    {
        return DB::transaction(function () use ($request, $user): AiRequest {
            $request->votes()->where('user_id', $user->id)->delete();

            return $this->refresh($request);
        });
    }

    private function refresh(AiRequest $request): AiRequest
    {
        $request->votes_count = $request->votes()->count();
        $request->recomputePriority();
        $request->save();

        return $request;
    }
}
