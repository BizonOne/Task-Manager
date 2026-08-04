<?php

namespace App\Support;

/**
 * The permissions each role starts with.
 *
 * These are chosen to reproduce exactly what the app did before there was a
 * matrix to edit — so installing this changes nobody's access on the day it
 * ships. Everything after that is somebody's deliberate decision on the
 * settings screen.
 *
 * super_admin is absent on purpose: it bypasses the check entirely rather than
 * holding a copy of every key that would then need keeping in step.
 */
class PermissionDefaults
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function all(): array
    {
        return [
            'member' => self::member(),
            'admin' => self::admin(),
        ];
    }

    /**
     * What a member could already do.
     *
     * Projects and tasks: see everything in a project you are on — that is what
     * a shared board is for — but only change what is yours. "Own" is listed
     * alongside "team" rather than implied by it: somebody can be assigned to
     * a task in a project they are not a member of, and that task is still
     * theirs to look at.
     *
     * Notes, reminders and routines are personal and were never anyone else's
     * business.
     *
     * @return array<int, string>
     */
    public static function member(): array
    {
        return [
            'system.export-reports',
            'system.archive-tasks',
            'system.link-tasks',

            'project.create',
            'project.view.own',
            'project.view.team',
            'project.edit.own',
            'project.delete.own',

            'task.create',
            'task.view.own',
            'task.view.team',
            'task.edit.own',
            'task.delete.own',

            'comment.create',
            'comment.view.own',
            'comment.view.team',
            'comment.edit.own',
            'comment.delete.own',

            'file.create',
            'file.view.own',
            'file.view.team',
            'file.edit.own',
            'file.delete.own',

            'note.create',
            'note.view.own',
            'note.edit.own',
            'note.delete.own',

            'reminder.create',
            'reminder.view.own',
            'reminder.edit.own',
            'reminder.delete.own',

            'routine.create',
            'routine.view.own',
            'routine.edit.own',
            'routine.delete.own',
        ];
    }

    /**
     * An admin oversees: they see and change work across projects.
     *
     * Not personal notes, reminders and routines, though — overseeing the work
     * is not a reason to read somebody's private to-do list.
     *
     * @return array<int, string>
     */
    public static function admin(): array
    {
        $keys = array_keys(Permissions::SYSTEM);

        foreach (['project', 'task', 'comment', 'file'] as $entity) {
            $keys[] = $entity.'.create';
            foreach (['view', 'edit', 'delete'] as $action) {
                $keys[] = $entity.'.'.$action.'.all';
            }
        }

        foreach (['note', 'reminder', 'routine'] as $entity) {
            $keys[] = $entity.'.create';
            foreach (['view', 'edit', 'delete'] as $action) {
                $keys[] = $entity.'.'.$action.'.own';
            }
        }

        return $keys;
    }
}
