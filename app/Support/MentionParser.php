<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves @mentions inside a comment body against a set of candidate users
 * (a task's participants). A user can be mentioned by their full-name slug
 * ("@arafat-hossain") or, when unambiguous, by first name ("@arafat").
 */
class MentionParser
{
    /**
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User> the mentioned candidates, unique
     */
    public static function resolve(string $body, Collection $candidates): Collection
    {
        $handles = self::buildHandleMap($candidates);

        preg_match_all('/@([\p{L}0-9_-]+)/u', $body, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $token): string => Str::lower($token))
            ->map(fn (string $handle): ?User => $handles[$handle] ?? null)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Map lookup handles to users. Full-name slugs always map; a first-name
     * handle only maps when a single candidate owns it.
     *
     * @param  Collection<int, User>  $candidates
     * @return array<string, User>
     */
    private static function buildHandleMap(Collection $candidates): array
    {
        $map = [];
        $firstNameCounts = [];

        foreach ($candidates as $user) {
            $first = Str::lower(Str::slug(Str::before(trim($user->name), ' ')));
            if ($first !== '') {
                $firstNameCounts[$first] = ($firstNameCounts[$first] ?? 0) + 1;
            }
        }

        foreach ($candidates as $user) {
            $full = Str::lower(Str::slug($user->name));
            if ($full !== '') {
                $map[$full] = $user;
            }

            $first = Str::lower(Str::slug(Str::before(trim($user->name), ' ')));
            if ($first !== '' && ($firstNameCounts[$first] ?? 0) === 1) {
                $map[$first] = $user;
            }
        }

        return $map;
    }
}
