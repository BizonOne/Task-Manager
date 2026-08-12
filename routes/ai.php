<?php

use App\Mcp\Servers\TaskManagerServer;
use Laravel\Mcp\Facades\Mcp;

// The task manager for AI agents. Authentication is a personal access
// token issued on the profile page — the agent acts as that person, and
// the throttle keeps a runaway loop from hammering the boards.
Mcp::web('/mcp', TaskManagerServer::class)
    ->middleware(['auth:sanctum', 'throttle:60,1']);
