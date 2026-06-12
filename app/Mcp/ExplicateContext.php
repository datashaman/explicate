<?php

namespace App\Mcp;

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Brief;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Thread;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Throwable;

class ExplicateContext
{
    public function requireUser(?User $user): User
    {
        if ($user instanceof User) {
            return $user;
        }

        if (app()->runningInConsole()) {
            try {
                app(LocalMcpUserAuthenticator::class)->authenticate();
            } catch (Throwable $exception) {
                report($exception);

                throw new AuthenticationException('The configured MCP local auth user could not be resolved.');
            }

            $user = auth()->user();

            if ($user instanceof User) {
                return $user;
            }
        }

        throw new AuthenticationException('You must be authenticated to use the Explicate MCP server.');
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

    public function threadFor(User $user, string $slugOrId, ?string $workspaceSlug = null): Thread
    {
        [$slugOrId, $workspaceSlug] = $this->normalizeThreadReference($slugOrId, $workspaceSlug);

        $workspace = $this->workspaceFor($user, $workspaceSlug);

        $thread = $workspace->threads()
            ->where(function ($query) use ($slugOrId): void {
                $query->where('slug', $slugOrId)
                    ->when(is_numeric($slugOrId), fn ($query) => $query->orWhereKey((int) $slugOrId));
            })
            ->first();

        if (! $thread instanceof Thread) {
            throw new AuthorizationException('The requested thread is not accessible for the authenticated user.');
        }

        return $thread;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function normalizeThreadReference(string $threadReference, ?string $workspaceSlug): array
    {
        $threadReference = trim($threadReference);
        $parsedUrl = parse_url($threadReference);

        if (($parsedUrl['scheme'] ?? null) === 'explicate' && ($parsedUrl['host'] ?? null) === 'workspaces') {
            $segments = $this->pathSegments($parsedUrl['path'] ?? '');

            if (count($segments) >= 3 && $segments[1] === 'threads') {
                return [
                    rawurldecode($segments[2]),
                    $workspaceSlug ?: rawurldecode($segments[0]),
                ];
            }
        }

        if (str_contains($threadReference, '?')) {
            parse_str($parsedUrl['query'] ?? '', $query);

            if (is_string($query['thread'] ?? null) && filled($query['thread'])) {
                return [$query['thread'], $workspaceSlug];
            }
        }

        return [$threadReference, $workspaceSlug];
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), fn (string $segment): bool => $segment !== ''));
    }

    public function postFor(User $user, string $postUlid, ?string $workspaceSlug = null): Post
    {
        $workspace = $this->workspaceFor($user, $workspaceSlug);

        $post = Post::query()
            ->where('ulid', $postUlid)
            ->whereHas('thread', fn ($query) => $query->whereBelongsTo($workspace))
            ->first();

        if (! $post instanceof Post) {
            throw new AuthorizationException('The requested post is not accessible for the authenticated user.');
        }

        return $post;
    }

    public function briefFor(User $user, int|string $briefId, ?string $workspaceSlug = null): Brief
    {
        $workspace = $this->workspaceFor($user, $workspaceSlug);

        $brief = $workspace->briefs()
            ->whereKey((int) $briefId)
            ->first();

        if (! $brief instanceof Brief) {
            throw new AuthorizationException('The requested brief is not accessible for the authenticated user.');
        }

        return $brief;
    }

    public function planFor(User $user, int|string $briefId, ?string $workspaceSlug = null): Plan
    {
        $brief = $this->briefFor($user, $briefId, $workspaceSlug);

        $plan = $brief->plan()->first();

        if (! $plan instanceof Plan) {
            throw new AuthorizationException('The requested brief does not have a plan.');
        }

        return $plan;
    }

    public function agentTaskFor(User $user, string $agentSlug, int $taskId, ?string $workspaceSlug = null): AgentTask
    {
        $agent = $this->agentFor($user, $agentSlug, $workspaceSlug);

        $task = $agent->tasks()->whereKey($taskId)->first();

        if (! $task instanceof AgentTask) {
            throw new AuthorizationException('The requested agent task is not accessible for the authenticated user.');
        }

        return $task;
    }
}
