<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddComment;
use App\Mcp\Tools\GetProject;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\ReadAttachment;
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
        every tool accepts any of the three.

        A typical run: get_task to read the work, do it, add_comment to
        report the outcome, update_task_status to move the task on the
        board. You act as the person whose token you hold; everything you
        write lands in the task history under their name, so write comments
        you would be happy to sign.
        TEXT;

    protected function boot(): void
    {
        $this->tools = [
            new GetTask,
            new ListTasks,
            new AddComment,
            new UpdateTaskStatus,
            new ReadAttachment,
            new GetProject,
        ];
    }
}
