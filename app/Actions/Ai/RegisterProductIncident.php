<?php

namespace App\Actions\Ai;

use App\Models\AiProduct;
use App\Models\AiProductIncident;
use App\Models\User;
use App\Notifications\ProductIncidentRegistered;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Registers an incident by hand (C-IncidentForm, PROMPT 6.4.2) while
 * monitoring is off: `origin` manual, the reporter is the signed-in person.
 * The chosen IT people, plus the product owner when asked (and when the
 * owner is a user), get `ProductIncidentRegistered`. Logged as
 * `product_incident` (log `ai`) on the product.
 */
final class RegisterProductIncident
{
    /**
     * @param  list<int>  $notifyIds
     */
    public function handle(
        User $reporter,
        AiProduct $product,
        string $title,
        string $impact,
        string $description,
        CarbonImmutable $startedAt,
        ?CarbonImmutable $endedAt,
        ?string $resolution,
        array $notifyIds,
        bool $notifyOwner,
    ): AiProductIncident {
        $recipients = User::query()->whereIn('id', $notifyIds)->where('active', true)->get();

        if ($notifyOwner && $product->owner_user_id !== null) {
            $owner = User::query()->whereKey($product->owner_user_id)->where('active', true)->first();
            if ($owner !== null && ! $recipients->contains('id', $owner->id)) {
                $recipients->push($owner);
            }
        }

        $incident = $product->incidents()->create([
            'title' => trim($title),
            'impact' => $impact,
            'description' => trim($description),
            'started_at' => $startedAt->setTimezone(config('app.timezone')),
            'resolved_at' => $endedAt?->setTimezone(config('app.timezone')),
            'resolution' => $endedAt === null || $resolution === null ? null : trim($resolution),
            'origin' => 'manual',
            'reported_by_id' => $reporter->id,
            'notified_user_ids' => $recipients->modelKeys(),
        ]);

        activity('ai')
            ->performedOn($product)
            ->causedBy($reporter)
            ->event('product_incident')
            ->withProperties(['incident_id' => $incident->id, 'impact' => $impact, 'resolved' => $endedAt !== null])
            ->log("Incidente registrado en {$product->name}: {$incident->title}");

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, ProductIncidentRegistered::for($product, $incident, $reporter->name));
        }

        return $incident;
    }
}
