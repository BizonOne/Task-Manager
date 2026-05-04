<?php

namespace App\Services;

use App\Models\File;
use App\Models\Note;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Routine;
use App\Models\Task;
use Carbon\Carbon;

/**
 * LinaFallbackBrain — offline intent-aware assistant.
 * Works entirely in PHP with DB queries. No external API needed.
 * Used when Groq is unavailable or rate-limited.
 *
 * Text/copy is loaded from resources/ai/lina_dataset.json — edit that
 * file to add responses, suggestions, labels, etc. without touching PHP.
 */
class LinaFallbackBrain
{
    private $user;
    private string $msg;
    private string $low; // lowercase message
    private array $ds;   // dataset from JSON

    public function __construct($user, string $message)
    {
        $this->user = $user;
        $this->msg  = $message;
        $this->low  = strtolower(trim($message));
        $this->ds   = json_decode(
            file_get_contents(resource_path('ai/lina_dataset.json')),
            true
        ) ?? [];
    }

    /** Entry point — returns a markdown string */
    public function respond(): string
    {
        // Identity / meta
        if ($this->rx('/\b(who are you|your name|what are you|who made you|who built you|who created you|about (you|lina)|tell me about)\b/'))
            return $this->aboutLina();

        if ($this->rx('/\b(what can you do|how can you help|your (capabilities|features)|help me|what do you know)\b/'))
            return $this->helpResponse();

        // Date / time
        if ($this->rx('/\b(today[\'s]? date|current date|what.*date|what.*day is|what time)\b/'))
            return $this->dateResponse();

        // Greetings
        if ($this->rx('/^(hi|hello|hey|howdy|good (morning|afternoon|evening)|greetings|yo|sup)\b/'))
            return $this->greeting();

        // Thanks
        if ($this->kw(['thank you', 'thanks', 'cheers', 'appreciated']))
            return $this->thanks();

        // Summary / dashboard
        if ($this->kw(['summary', 'overview', 'report', 'dashboard', 'status update', 'give me a summary']))
            return $this->summary();

        // Tasks — specific queries first
        if ($this->rx('/\b(overdue|past due|late|missed deadline)\b/'))
            return $this->overdueTasks();

        if ($this->rx('/\b(due today|tasks? (due )?today|today[\'s]? tasks?)\b/'))
            return $this->tasksToday();

        if ($this->rx('/\b(due tomorrow|tasks? (due )?tomorrow|tomorrow[\'s]? tasks?)\b/'))
            return $this->tasksTomorrow();

        if ($this->rx('/\b(high.?priority|urgent|critical|important)\b.*\btasks?\b|\btasks?\b.*\b(high.?priority|urgent|critical)\b/'))
            return $this->highPriorityTasks();

        if ($this->rx('/\bhow many\b.*\btasks?\b|\btask\b.*\bcount\b|\btotal tasks?\b/'))
            return $this->taskCount();

        if ($this->rx('/\bcompleted?\b.*\btasks?\b|\btasks?\b.*\bcompleted?\b|\bfinished tasks?\b/'))
            return $this->completedTasks();

        if ($this->kw(['task', 'tasks', 'todo', 'to-do', 'to do', 'pending', 'incomplete', 'unfinished']))
            return $this->allTasks();

        // Projects
        if ($this->kw(['project', 'projects']))
            return $this->projects();

        // Reminders
        if ($this->kw(['reminder', 'reminders', 'upcoming reminder']))
            return $this->reminders();

        // Notes
        if ($this->kw(['note', 'notes', 'my notes']))
            return $this->notes();

        // Routines
        if ($this->kw(['routine', 'routines', 'habit', 'habits', 'daily routine']))
            return $this->routines();

        // Files
        if ($this->kw(['file', 'files', 'document', 'documents', 'attachment', 'uploads']))
            return $this->files();

        // Catch-all
        return $this->unknown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Replace {count} placeholder in a label from the dataset */
    private function label(string $key, int $count = 0): string
    {
        $tpl = $this->ds['labels'][$key] ?? $key;
        return str_replace('{count}', $count, $tpl);
    }

    private function kw(array $words): bool
    {
        foreach ($words as $w) {
            if (str_contains($this->low, $w)) return true;
        }
        return false;
    }

    private function rx(string $pattern): bool
    {
        return (bool) preg_match($pattern . 'i', $this->low);
    }

    private function priorityIcon(string $priority): string
    {
        $icons = $this->ds['priority_icons'] ?? [];
        return $icons[strtolower($priority)] ?? ($icons['default'] ?? '⚪');
    }

    // ── Intents ───────────────────────────────────────────────────────────────

    private function aboutLina(): string
    {
        $a    = $this->ds['about'] ?? [];
        $name = $this->user->name;
        $intro = str_replace('{name}', $name, $a['intro'] ?? "I'm **Lina**, your assistant.");
        $r  = $intro . "\n\n";
        $r .= ($a['description'] ?? '') . "\n";
        foreach ($a['capabilities'] ?? [] as $cap) {
            $r .= "- {$cap}\n";
        }
        $r .= "\n" . ($a['offline_note'] ?? '');
        return $r;
    }

    private function helpResponse(): string
    {
        $h = $this->ds['help'] ?? [];
        $r = ($h['intro'] ?? 'Here\'s what I can help you with:') . "\n\n";
        foreach ($h['sections'] ?? [] as $section) {
            $r .= ($section['heading'] ?? '') . "\n";
            foreach ($section['items'] ?? [] as $item) {
                $r .= "- {$item}\n";
            }
            $r .= "\n";
        }
        $r .= ($h['offline_note'] ?? '');
        return $r;
    }

    private function dateResponse(): string
    {
        $today = now()->format('l, F j, Y');
        $time  = now()->format('g:i A');
        $dueToday = Task::where('user_id', $this->user->id)
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', today())->count();

        $r = "Today is **{$today}** · {$time}\n\n";
        if ($dueToday > 0) {
            $r .= "> 📅 You have **{$dueToday} task" . ($dueToday > 1 ? 's' : '') . " due today**. Want me to list them?";
        } else {
            $r .= "> ✅ No tasks due today — you're clear!";
        }
        return $r;
    }

    private function greeting(): string
    {
        $name  = $this->user->name;
        $hour  = (int) now()->format('G');
        $g     = $this->ds['greetings'] ?? [];
        $greet = $hour < 12
            ? ($g['morning']   ?? 'Good morning')
            : ($hour < 17 ? ($g['afternoon'] ?? 'Good afternoon') : ($g['evening'] ?? 'Good evening'));

        $dueToday = Task::where('user_id', $this->user->id)
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', today())->count();
        $overdue = Task::where('user_id', $this->user->id)
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', '<', today())->count();

        $r = "**{$greet}, {$name}!** 👋\n\n";
        if ($overdue > 0 && $dueToday > 0) {
            $r .= "Quick heads up:\n- 📅 **{$dueToday} task" . ($dueToday > 1 ? 's' : '') . " due today**\n- ⚠️ **{$overdue} overdue task" . ($overdue > 1 ? 's' : '') . "** need attention\n\nWant me to show them?";
        } elseif ($dueToday > 0) {
            $r .= "You have **{$dueToday} task" . ($dueToday > 1 ? 's' : '') . " due today**. Want me to show them?";
        } elseif ($overdue > 0) {
            $r .= "⚠️ You have **{$overdue} overdue task" . ($overdue > 1 ? 's' : '') . "** that need attention. Should I list them?";
        } else {
            $r .= "You're all caught up on tasks! 🎉 How can I help you today?";
        }
        return $r;
    }

    private function tasksToday(): string
    {
        $tasks = Task::where('user_id', $this->user->id)
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', today())
            ->with('project:id,name')
            ->orderByRaw("FIELD(priority, 'high', 'urgent', 'medium', 'low')")
            ->get();

        if ($tasks->isEmpty()) {
            return $this->ds['empty_states']['tasks_today'] ?? '🎉 No tasks due today!';
        }

        $r = $this->label('tasks_today_heading', $tasks->count()) . "\n\n";
        foreach ($tasks as $t) {
            $icon    = $this->priorityIcon($t->priority ?? '');
            $project = $t->project ? " · 📁 {$t->project->name}" : '';
            $r .= "- {$icon} **{$t->title}**{$project}\n";
        }
        return $r;
    }

    private function tasksTomorrow(): string
    {
        $tasks = Task::where('user_id', $this->user->id)
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', today()->addDay())
            ->with('project:id,name')
            ->get();

        if ($tasks->isEmpty()) {
            return $this->ds['empty_states']['tasks_tomorrow'] ?? '📅 No tasks due tomorrow.';
        }

        $r = $this->label('tasks_tomorrow_heading', $tasks->count()) . "\n\n";
        foreach ($tasks as $t) {
            $icon = $this->priorityIcon($t->priority ?? '');
            $r .= "- {$icon} **{$t->title}**\n";
        }
        return $r;
    }

    private function overdueTasks(): string
    {
        $tasks = Task::where('user_id', $this->user->id)
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', '<', today())
            ->with('project:id,name')
            ->orderBy('due_date')
            ->get();

        if ($tasks->isEmpty()) {
            return $this->ds['empty_states']['overdue'] ?? '✅ No overdue tasks!';
        }

        $r = $this->label('overdue_heading', $tasks->count()) . "\n\n";
        foreach ($tasks as $t) {
            $due  = $t->due_date ? ' · was due ' . Carbon::parse($t->due_date)->format('M j') : '';
            $icon = $this->priorityIcon($t->priority ?? '');
            $r .= "- {$icon} **{$t->title}**{$due}\n";
        }
        $r .= "\n" . ($this->ds['labels']['overdue_footer'] ?? '');
        return $r;
    }

    private function highPriorityTasks(): string
    {
        $tasks = Task::where('user_id', $this->user->id)
            ->whereIn('priority', ['high', 'urgent', 'critical'])
            ->where('status', '!=', 'completed')
            ->orderBy('due_date')
            ->get();

        if ($tasks->isEmpty()) {
            return $this->ds['empty_states']['high_priority'] ?? '✅ No high priority pending tasks.';
        }

        $r = $this->label('high_priority_heading', $tasks->count()) . "\n\n";
        foreach ($tasks as $t) {
            $due = $t->due_date ? ' · due ' . Carbon::parse($t->due_date)->format('M j') : '';
            $r .= "- **{$t->title}**{$due}\n";
        }
        return $r;
    }

    private function taskCount(): string
    {
        $uid  = $this->user->id;
        $total     = Task::where('user_id', $uid)->count();
        $completed = Task::where('user_id', $uid)->where('status', 'completed')->count();
        $pending   = $total - $completed;
        $overdue   = Task::where('user_id', $uid)->where('status', '!=', 'completed')->whereDate('due_date', '<', today())->count();
        $today     = Task::where('user_id', $uid)->where('status', '!=', 'completed')->whereDate('due_date', today())->count();

        return ($this->ds['labels']['task_stats_heading'] ?? '**Your task stats:**') . "\n\n"
             . "| Status | Count |\n|---|---|\n"
             . "| 📋 Total | **{$total}** |\n"
             . "| ⏳ Pending | **{$pending}** |\n"
             . "| ✅ Completed | **{$completed}** |\n"
             . "| 📅 Due Today | **{$today}** |\n"
             . ($overdue > 0 ? "| ⚠️ Overdue | **{$overdue}** |\n" : '');
    }

    private function completedTasks(): string
    {
        $tasks = Task::where('user_id', $this->user->id)
            ->where('status', 'completed')
            ->latest('updated_at')
            ->limit(10)
            ->get();

        if ($tasks->isEmpty()) {
            return $this->ds['empty_states']['completed'] ?? 'No completed tasks yet.';
        }

        $r = $this->label('completed_heading', $tasks->count()) . "\n\n";
        foreach ($tasks as $t) {
            $r .= "- ~~{$t->title}~~\n";
        }
        return $r;
    }

    private function allTasks(): string
    {
        $tasks = Task::where('user_id', $this->user->id)
            ->where('status', '!=', 'completed')
            ->with('project:id,name')
            ->orderByRaw("FIELD(priority, 'high', 'urgent', 'medium', 'low')")
            ->limit(15)
            ->get();

        if ($tasks->isEmpty()) {
            return $this->ds['empty_states']['all_tasks'] ?? '🎉 No pending tasks!';
        }

        $r = $this->label('all_tasks_heading') . "\n\n";
        foreach ($tasks as $t) {
            $icon    = $this->priorityIcon($t->priority ?? '');
            $due     = $t->due_date ? ' · ' . Carbon::parse($t->due_date)->format('M j') : '';
            $project = $t->project ? " · 📁 {$t->project->name}" : '';
            $r .= "- {$icon} **{$t->title}**{$due}{$project}\n";
        }
        return $r;
    }

    private function projects(): string
    {
        $projects = Project::where('user_id', $this->user->id)->get();

        if ($projects->isEmpty()) {
            return $this->ds['empty_states']['projects'] ?? 'You have no projects yet.';
        }

        $r = $this->label('projects_heading', $projects->count()) . "\n\n";
        foreach ($projects as $p) {
            $due    = $p->end_date ? ' · due ' . Carbon::parse($p->end_date)->format('M j, Y') : '';
            $status = $p->status  ? " · _{$p->status}_" : '';
            $r .= "- **{$p->name}**{$status}{$due}\n";
        }
        return $r;
    }

    private function reminders(): string
    {
        $reminders = Reminder::where('user_id', $this->user->id)
            ->where('is_completed', false)
            ->orderBy('date')
            ->limit(12)
            ->get();

        if ($reminders->isEmpty()) {
            return $this->ds['empty_states']['reminders'] ?? '✅ No pending reminders!';
        }

        $r = $this->label('reminders_heading', $reminders->count()) . "\n\n";
        foreach ($reminders as $rem) {
            $when = $rem->date
                ? ' · ' . Carbon::parse($rem->date)->format('M j') . ($rem->time ? ' at ' . $rem->time : '')
                : '';
            $r .= "- ⏰ **{$rem->title}**{$when}\n";
        }
        return $r;
    }

    private function notes(): string
    {
        $notes = Note::where('user_id', $this->user->id)->latest()->limit(8)->get();

        if ($notes->isEmpty()) {
            return $this->ds['empty_states']['notes'] ?? 'You have no notes yet.';
        }

        $r = $this->label('notes_heading', $notes->count()) . "\n\n";
        foreach ($notes as $n) {
            $preview = trim(strip_tags(substr($n->content ?? '', 0, 90)));
            $r .= "- 📝 **{$n->title}**" . ($preview ? ": _{$preview}…_" : '') . "\n";
        }
        return $r;
    }

    private function routines(): string
    {
        $routines = Routine::where('user_id', $this->user->id)->get();

        if ($routines->isEmpty()) {
            return $this->ds['empty_states']['routines'] ?? 'You have no routines yet.';
        }

        $r = $this->label('routines_heading', $routines->count()) . "\n\n";
        foreach ($routines as $rt) {
            $r .= "- 🔄 **{$rt->title}** · _{$rt->frequency}_\n";
        }
        return $r;
    }

    private function files(): string
    {
        $files = File::where('user_id', $this->user->id)->latest()->limit(10)->get();

        if ($files->isEmpty()) {
            return $this->ds['empty_states']['files'] ?? 'You have no files uploaded yet.';
        }

        $r = $this->label('files_heading', $files->count()) . "\n\n";
        foreach ($files as $f) {
            $r .= "- 📎 **{$f->name}** · _{$f->type}_\n";
        }
        return $r;
    }

    private function summary(): string
    {
        $uid      = $this->user->id;
        $pending  = Task::where('user_id', $uid)->where('status', '!=', 'completed')->count();
        $today    = Task::where('user_id', $uid)->where('status', '!=', 'completed')->whereDate('due_date', today())->count();
        $overdue  = Task::where('user_id', $uid)->where('status', '!=', 'completed')->whereDate('due_date', '<', today())->count();
        $projects = Project::where('user_id', $uid)->count();
        $rems     = Reminder::where('user_id', $uid)->where('is_completed', false)->count();
        $notes    = Note::where('user_id', $uid)->count();
        $routines = Routine::where('user_id', $uid)->count();
        $date     = now()->format('l, F j, Y');
        $lb       = $this->ds['labels'] ?? [];

        $heading = str_replace('{date}', $date, $lb['summary_heading'] ?? '## 📊 Workspace Summary');
        $r  = $heading . "\n\n";
        $r .= "| Area | Count |\n|---|---|\n";
        $r .= "| ⏳ Pending Tasks | **{$pending}** |\n";
        $r .= "| 📅 Due Today | **{$today}** |\n";
        if ($overdue > 0) $r .= "| ⚠️ Overdue | **{$overdue}** |\n";
        $r .= "| 📁 Projects | **{$projects}** |\n";
        $r .= "| ⏰ Pending Reminders | **{$rems}** |\n";
        $r .= "| 📝 Notes | **{$notes}** |\n";
        $r .= "| 🔄 Routines | **{$routines}** |\n";

        if ($overdue > 0) {
            $footer = str_replace('{count}', $overdue, $lb['summary_footer_overdue'] ?? "> ⚠️ **{$overdue} overdue task(s)** need attention!");
        } elseif ($today > 0) {
            $footer = str_replace('{count}', $today, $lb['summary_footer_today'] ?? "> 📅 **{$today} task(s) due today** — let's go!");
        } else {
            $footer = $lb['summary_footer_clear'] ?? '> ✅ You\'re all caught up! Great work.';
        }
        $r .= "\n" . $footer;

        return $r;
    }

    private function thanks(): string
    {
        $opts = $this->ds['thanks'] ?? ["You're welcome! 😊"];
        return $opts[array_rand($opts)];
    }

    private function unknown(): string
    {
        $u    = $this->ds['unknown'] ?? [];
        $suggestions = $u['suggestions'] ?? ['"Show my tasks"'];
        shuffle($suggestions);
        $top = array_slice($suggestions, 0, 3);

        $r  = ($u['intro']  ?? "I'm **Lina** running in **Offline Mode**.") . "\n\n";
        $r .= ($u['prompt'] ?? 'Here are some things you can ask me:') . "\n";
        $r .= "- " . implode("\n- ", $top) . "\n\n";
        $r .= ($u['footer'] ?? '');
        return $r;
    }
}
