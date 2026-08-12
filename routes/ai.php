<?php

use App\Mcp\Servers\TaskManagerServer;
use Laravel\Mcp\Facades\Mcp;

// The task manager for AI agents, reachable two ways: a hand-issued token
// from the profile page (Sanctum), or an OAuth grant a connector like
// claude.ai negotiates for itself (Passport). Either way the agent acts as
// the person behind the credential, and the throttle keeps a runaway loop
// from hammering the boards.
Mcp::web('/mcp', TaskManagerServer::class)
    ->middleware(['auth:sanctum,api', 'throttle:60,1']);

// OAuth discovery and dynamic client registration, so a connector pointed
// at /mcp can find the authorize/token endpoints and enrol itself without
// anyone copying client ids around.
Mcp::oauthRoutes();
