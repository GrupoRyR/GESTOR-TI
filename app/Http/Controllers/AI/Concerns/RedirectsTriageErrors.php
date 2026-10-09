<?php

namespace App\Http\Controllers\AI\Concerns;

use App\Domain\Ai\InvalidTransition;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * IT actions answer like the rest of the app: back to the page with a
 * flash message. A move the lifecycle does not allow (InvalidTransition)
 * becomes the `error` flash; validation errors keep their field keys.
 */
trait RedirectsTriageErrors
{
    /**
     * @param  Closure(): (string|null)  $action  returns the success message
     */
    protected function attempt(Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (InvalidTransition $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $message === null ? back() : back()->with('success', $message);
    }

    /**
     * Same as `attempt()`, but validation errors also become the `error`
     * flash (actions without a form field, such as "Recordar").
     *
     * @param  Closure(): (string|null)  $action
     */
    protected function attemptQuietly(Closure $action): RedirectResponse
    {
        try {
            return $this->attempt($action);
        } catch (ValidationException $exception) {
            return back()->with('error', (string) collect($exception->errors())->flatten()->first());
        }
    }
}
