<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddChecklistItem;
use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\AssignTask;
use App\Mcp\Tools\CreateTask;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\ReadAttachment;
use App\Mcp\Tools\SetChecklistItem;
use App\Mcp\Tools\UpdateTaskStatus;
use Laravel\Mcp\Server;

/**
 * The task manager as an AI agent sees it.
 *
 * An agent holds a personal access token and acts as the person who issued
 * it — the same projects, the same boards, the same audit trail. Nothing
 * here grants an agent anything its person could not do by hand.
 */
class TaskManagerServer extends Server
{
    protected string $name = 'Task Manager';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'TEXT'
        This server exposes a task manager. Tasks live on project boards and
        are referred to by id, by key (TASK-0078), or by URL (/tasks/78) —
        every tool accepts any of the three. People are referred to as "me",
        a name, or an email.

        A typical run: get_task to read the work, do it, add_comment to
        report the outcome, update_task_status to move the task on the
        board. You can also file new tasks, keep checklists, and hand tasks
        to people. You act as the person whose credential you hold;
        everything you do lands in the task history under their name, so
        act as they would want to be seen acting.
        TEXT;

    protected function boot(): void
    {
        $this->tools = [
            new GetTask,
            new ListTasks,
            new CreateTask,
            new AddComment,
            new UpdateTaskStatus,
            new AssignTask,
            new AddChecklistItem,
            new SetChecklistItem,
            new ReadAttachment,
            new GetProject,
        ];
    }
}
