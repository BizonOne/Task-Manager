<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turning "me", a name or an email into a person.
 *
 * An agent is told "assign this to Lika", not "assign this to user 12".
 * Email matches exactly; a name may match loosely but must match one
 * person — guessing between two Likas is how work lands on the wrong desk.
 */
trait ResolvesPeople
{
    /**
     * @return User|Collection<int, User> the person, or the candidates when
     *                                    the reference does not pin one down
     */
    protected function resolvePerson(string $reference, User $acting): User|Collection
    {
        $reference = trim($reference);

        if ($reference === '' || strcasecmp($reference, 'me') === 0) {
            return $acting;
        }

        if (filter_var($reference, FILTER_VALIDATE_EMAIL)) {
            $byEmail = User::where('email', $reference)->get();

            if ($byEmail->count() === 1) {
                return $byEmail->first();
            }

            return $byEmail;
        }

        $byName = User::where('name', 'like', '%'.$reference.'%')->limit(6)->get();

        return $byName->count() === 1 ? $byName->first() : $byName;
    }

    /**
     * The sentence an agent can act on when a reference matched nobody or
     * several somebodies.
     */
    protected function describePersonMiss(string $reference, Collection $candidates): string
    {
        if ($candidates->isEmpty()) {
            return 'Nobody matches "'.$reference.'". Use their exact email, or "me".';
        }

        return '"'.$reference.'" matches several people: '
            .$candidates->map(fn (User $u) => $u->name.' ('.$u->email.')')->implode(', ')
            .'. Use the email of the one you mean.';
    }
}
