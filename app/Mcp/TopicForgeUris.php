<?php

namespace App\Mcp;

use App\Data\UserWorkspace;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Post;
use App\Models\Topic;
use App\Models\Workspace;

final class TopicForgeUris
{
    public const Whoami = 'topic-forge://whoami';

    public const Playbook = 'topic-forge://playbook';

    public const Workspaces = 'topic-forge://workspaces';

    public const WorkspaceTopicsTemplate = 'topic-forge://workspaces/{workspace}/topics';

    public const WorkspaceAgentsTemplate = 'topic-forge://workspaces/{workspace}/agents';

    public const WorkspaceFilesTemplate = 'topic-forge://workspaces/{workspace}/files';

    public const WorkspaceFileTemplate = 'topic-forge://workspaces/{workspace}/files/{path}';

    public const TopicTemplate = 'topic-forge://workspaces/{workspace}/topics/{topic}';

    public const TopicPostsTemplate = 'topic-forge://workspaces/{workspace}/topics/{topic}/posts';

    public const PostTemplate = 'topic-forge://workspaces/{workspace}/topics/{topic}/posts/{post}';

    public const AgentTemplate = 'topic-forge://workspaces/{workspace}/agents/{agent}';

    public const AgentTasksTemplate = 'topic-forge://workspaces/{workspace}/agents/{agent}/tasks';

    public const AgentTaskTemplate = 'topic-forge://workspaces/{workspace}/agents/{agent}/tasks/{task}';

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

    public static function post(Post $post): string
    {
        $post->loadMissing('topic.workspace');

        return self::topic($post->topic)."/posts/{$post->ulid}";
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
