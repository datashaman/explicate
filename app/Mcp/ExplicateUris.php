<?php

namespace App\Mcp;

use App\Data\UserWorkspace;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\Thread;
use App\Models\Topic;
use App\Models\Workspace;

final class ExplicateUris
{
    public const Whoami = 'explicate://whoami';

    public const Playbook = 'explicate://playbook';

    public const Workspaces = 'explicate://workspaces';

    public const WorkspaceTopicsTemplate = 'explicate://workspaces/{workspace}/topics';

    public const WorkspaceAgentsTemplate = 'explicate://workspaces/{workspace}/agents';

    public const WorkspaceFilesTemplate = 'explicate://workspaces/{workspace}/files';

    public const WorkspaceFileTemplate = 'explicate://workspaces/{workspace}/files/{path}';

    public const TopicTemplate = 'explicate://workspaces/{workspace}/topics/{topic}';

    public const TopicPostsTemplate = 'explicate://workspaces/{workspace}/topics/{topic}/posts';

    public const WorkspaceThreadsTemplate = 'explicate://workspaces/{workspace}/threads';

    public const ThreadTemplate = 'explicate://workspaces/{workspace}/threads/{thread}';

    public const PostTemplate = 'explicate://workspaces/{workspace}/posts/{post}';

    public const AgentTemplate = 'explicate://workspaces/{workspace}/agents/{agent}';

    public const AgentTasksTemplate = 'explicate://workspaces/{workspace}/agents/{agent}/tasks';

    public const AgentTaskTemplate = 'explicate://workspaces/{workspace}/agents/{agent}/tasks/{task}';

    public static function workspaceTopics(Workspace|UserWorkspace|string $workspace): string
    {
        return self::Workspaces.'/'.self::workspaceSlug($workspace).'/topics';
    }

    public static function workspaceAgents(Workspace|UserWorkspace|string $workspace): string
    {
        return self::Workspaces.'/'.self::workspaceSlug($workspace).'/agents';
    }

    public static function workspaceFiles(Workspace|UserWorkspace|string $workspace): string
    {
        return self::Workspaces.'/'.self::workspaceSlug($workspace).'/files';
    }

    public static function workspaceFile(Workspace $workspace, string $path): string
    {
        return self::workspaceFiles($workspace).'/'.rawurlencode($path);
    }

    public static function topic(Topic $topic): string
    {
        $topic->loadMissing('workspace');

        return self::Workspaces."/{$topic->workspace->slug}/topics/{$topic->slug}";
    }

    public static function topicPosts(Topic $topic): string
    {
        return self::topic($topic).'/posts';
    }

    public static function workspaceThreads(Workspace|UserWorkspace|string $workspace): string
    {
        return self::Workspaces.'/'.self::workspaceSlug($workspace).'/threads';
    }

    public static function thread(Thread $thread): string
    {
        $thread->loadMissing('workspace');

        return self::workspaceThreads($thread->workspace)."/{$thread->slug}";
    }

    public static function post(Post $post): string
    {
        $post->loadMissing('thread.workspace');

        return self::Workspaces."/{$post->thread->workspace->slug}/posts/{$post->ulid}";
    }

    public static function agent(Agent $agent): string
    {
        $agent->loadMissing('workspace');

        return self::Workspaces."/{$agent->workspace->slug}/agents/{$agent->slug}";
    }

    public static function agentTasks(Agent $agent): string
    {
        return self::agent($agent).'/tasks';
    }

    public static function agentTask(AgentTask $task): string
    {
        $task->loadMissing('agent.workspace');

        return self::agentTasks($task->agent)."/{$task->id}";
    }

    private static function workspaceSlug(Workspace|UserWorkspace|string $workspace): string
    {
        return is_string($workspace) ? $workspace : $workspace->slug;
    }
}
