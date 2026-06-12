<?php

namespace App\Mcp\Resources;

use App\Mcp\Concerns\FormatsMcpPayloads;
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

#[Description('Read the implementation plan for one brief from an accessible workspace.')]
class PlanResource extends Resource implements HasUriTemplate
{
    use FormatsMcpPayloads;
    use HandlesResourceExceptions;

    public function __construct(protected ExplicateContext $context) {}

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate(ExplicateUris::PlanTemplate);
    }

    public function handle(Request $request): Response
    {
        return $this->guardResource(function () use ($request): Response {
            /** @var User $user */
            $user = $this->context->requireUser($request->user());
            $plan = $this->context->planFor(
                $user,
                (string) $request->get('brief'),
                (string) $request->get('workspace'),
            );
            $plan->load(['brief.workspace', 'brief.sourceThread.workspace', 'tasks']);

            return Response::json([
                'workspace' => $plan->brief->workspace->only(['id', 'name', 'slug']),
                'plan' => $this->planPayload($plan),
            ]);
        });
    }
}
