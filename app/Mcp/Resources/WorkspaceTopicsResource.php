<?php

namespace App\Mcp\Resources;

use App\Mcp\ExplicateContext;
use App\Mcp\ExplicateUris;
use App\Mcp\Resources\Concerns\HandlesResourceExceptions;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('List optional topic labels for an accessible workspace.')]
class WorkspaceTopicsResource extends Resource implements HasUriTemplate
{
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::WorkspaceTopicsTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $workspace = $this->context->workspaceFor($user, (string) $request->get('workspace'));

            $topics = $workspace->topics()
                ->withCount('threads')
                ->get()
                ->map(fn ($topic) => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'slug' => $topic->slug,
                    'threads_count' => $topic->threads_count,
                    'resource_uri' => ExplicateUris::topic($topic),
                ])
                ->values()
                ->all();

            return Response::json([
                'workspace' => $workspace->only(['id', 'name', 'slug']),
                'topics' => $topics,
            ]);
        });
    }
}
