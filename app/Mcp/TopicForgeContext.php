<?php

namespace App\Mcp;

use App\Models\Agent;
use App\Models\Message;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;

class TopicForgeContext
{
    public function requireUser(?User $user): User
    {
        if (! $user instanceof User) {
            throw new AuthenticationException('You must be authenticated to use the Topic Forge MCP server.');
        }

        return $user;
    }

    public function workspaceFor(User $user, ?string $workspaceSlug = null): Workspace
    {
        $workspaceSlug = blank($workspaceSlug) ? null : $workspaceSlug;

        $workspace = $workspaceSlug === null
            ? $user->currentWorkspace
            : $user->currentTeam?->workspaces()->where('slug', $workspaceSlug)->first();

        if (! $workspace instanceof Workspace) {
            throw new AuthorizationException('The requested workspace is not accessible for the authenticated user.');
        }

        return $workspace;
    }

    public function topicFor(User $user, string $topicSlug, ?string $workspaceSlug = null): Topic
    {
        $workspace = $this->workspaceFor($user, $workspaceSlug);

        $topic = $workspace->topics()->where('slug', $topicSlug)->first();

        if (! $topic instanceof Topic) {
            throw new AuthorizationException('The requested topic is not accessible for the authenticated user.');
        }

        return $topic;
    }

    public function agentFor(User $user, string $agentSlug, ?string $workspaceSlug = null): Agent
    {
        $workspace = $this->workspaceFor($user, $workspaceSlug);

        $agent = $workspace->agents()->where('slug', $agentSlug)->first();

        if (! $agent instanceof Agent) {
            throw new AuthorizationException('The requested agent is not accessible for the authenticated user.');
        }

        return $agent;
    }

    public function messageFor(User $user, string $topicSlug, string $messageSlug, ?string $workspaceSlug = null): Message
    {
        $topic = $this->topicFor($user, $topicSlug, $workspaceSlug);

        $message = $topic->messages()->where('slug', $messageSlug)->first();

        if (! $message instanceof Message) {
            throw new AuthorizationException('The requested message is not accessible for the authenticated user.');
        }

        return $message;
    }
}
