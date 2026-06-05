<?php

use App\Mcp\Servers\TopicForgeServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp/topic-forge', TopicForgeServer::class)
    ->middleware(['auth:api']);

Mcp::local('topic-forge', TopicForgeServer::class);
