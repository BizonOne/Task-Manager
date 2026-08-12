<?php

namespace App\Observers;

use App\Models\ChecklistItem;
use App\Models\TaskActivity;

/**
 * Writes checklist changes into the task's history.
 *
 * Living on the model's events rather than in the controller means the
 * admin panel's checklist edits land in the same history as the task
 * page's — nobody has to remember to log anything.
 */
class ChecklistItemObserver
{
    public function created(ChecklistItem $item): void
    {
        $this->record($item, TaskActivity::EVENT_CHECKLIST_ADDED);
    }

    public function updated(ChecklistItem $item): void
    {
        if ($item->wasChanged('completed')) {
            $this->record($item, $item->completed
                ? TaskActivity::EVENT_CHECKLIST_DONE
                : TaskActivity::EVENT_CHECKLIST_UNDONE);
        }

        // A rename is a change of wording, not of state — the history shows
        // what it says now, attributed to whoever reworded it.
        if ($item->wasChanged('name')) {
            $this->record($item, TaskActivity::EVENT_CHECKLIST_RENAMED, [
                'renamed_from' => $item->getOriginal('name'),
            ]);
        }
    }

    public function deleted(ChecklistItem $item): void
    {
        $this->record($item, TaskActivity::EVENT_CHECKLIST_REMOVED);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(ChecklistItem $item, string $event, array $extra = []): void
    {
        // The item's wording travels in meta because the row itself may be
        // gone by the time anyone reads the history.
        if ($item->task !== null) {
            TaskActivity::record($item->task, $event, [
                'meta' => array_merge(['name' => $item->name], $extra),
            ]);
        }
    }
}
