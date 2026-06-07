<?php

namespace Database\Seeders;

use App\Enums\PostStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $team = $user->currentTeam;

        $workspace = Workspace::factory()->for($team)->create(['name' => 'My First Workspace']);
        $user->switchWorkspace($workspace);
        $senderPrincipal = $workspace->principalForUser($user);

        $topics = Topic::factory()->for($workspace)->createMany([
            ['name' => 'Design'],
            ['name' => 'Engineering'],
            ['name' => 'Marketing'],
            ['name' => 'Research'],
        ]);

        $agents = collect([
            [
                'name' => 'Writer',
                'provider' => Provider::OpenAI,
                'model' => 'o4-mini',
                'reasoning_effort' => ReasoningEffort::Medium,
                'prompt' => 'Write concise, high-signal drafts and tighten structure.',
            ],
            [
                'name' => 'Researcher',
                'provider' => Provider::Anthropic,
                'model' => 'claude-sonnet-4-6',
                'reasoning_effort' => null,
                'prompt' => 'Synthesize sources, note uncertainty, and surface edge cases.',
            ],
            [
                'name' => 'SEO Analyst',
                'provider' => Provider::Gemini,
                'model' => 'gemini-2.5-pro',
                'reasoning_effort' => null,
                'prompt' => 'Optimize content structure, discover keyword clusters, and flag gaps.',
            ],
            [
                'name' => 'Reviewer',
                'provider' => Provider::Groq,
                'model' => 'llama-3.3-70b-versatile',
                'reasoning_effort' => null,
                'prompt' => 'Review content for consistency, clarity, and missing assumptions.',
            ],
        ])->map(function (array $seededAgent) use ($workspace): Agent {
            $agent = $workspace->agents()->create([
                'name' => $seededAgent['name'],
                'slug' => Str::slug($seededAgent['name']),
            ]);

            $agent->versions()->create([
                'version' => 1,
                'provider' => $seededAgent['provider'],
                'model' => $seededAgent['model'],
                'reasoning_effort' => $seededAgent['reasoning_effort'],
                'prompt' => $seededAgent['prompt'],
                'created_at' => now(),
            ]);

            return $agent->fresh(['latestVersion']);
        });

        $topicsByName = $topics->keyBy('name');
        $agentsByName = $agents->keyBy('name');
        $attachAgents = function (Topic $topic, array $agentNames) use ($agentsByName): void {
            $topic->agents()->attach(
                collect($agentNames)
                    ->mapWithKeys(function (string $agentName) use ($agentsByName): array {
                        $agent = $agentsByName[$agentName];

                        return [$agent->id => ['agent_version_id' => $agent->latestVersion->id]];
                    })
                    ->all()
            );
        };

        $attachAgents($topicsByName['Design'], ['Writer', 'Reviewer']);
        $attachAgents($topicsByName['Engineering'], ['Researcher', 'Reviewer']);
        $attachAgents($topicsByName['Marketing'], ['Writer', 'SEO Analyst']);
        $attachAgents($topicsByName['Research'], ['Researcher']);

        $designDraft = Post::factory()->for($topicsByName['Design'])->create([
            'body' => "Homepage hero directions\n\nExplore three tonal directions for the homepage hero.\n\n1. Product-led\n2. Proof-led\n3. Narrative-led",
            'status' => PostStatus::Draft,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        Attachment::factory()->count(2)->for($designDraft)->create();

        Post::factory()->for($topicsByName['Design'])->create([
            'body' => "Brand voice notes\n\nCapture phrases to avoid and preferred tone examples.",
            'status' => PostStatus::Published,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        Post::factory()->for($topicsByName['Engineering'])->create([
            'body' => "Agent orchestration outline\n\nDocument the post lifecycle and queue boundaries.\n\nInclude failure handling and retry policy.",
            'status' => PostStatus::Draft,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        Post::factory()->for($topicsByName['Engineering'])->create([
            'body' => "Model fallback strategy\n\nCompare provider fallback order and expected quality tradeoffs.",
            'status' => PostStatus::Archived,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        Post::factory()->for($topicsByName['Marketing'])->create([
            'body' => "Q3 campaign angles\n\nList campaign themes tied to the strongest product outcomes.",
            'status' => PostStatus::Published,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        Post::factory()->for($topicsByName['Marketing'])->create([
            'body' => "Landing page test ideas\n\nPropose headline, CTA, and proof-module experiments.",
            'status' => PostStatus::Draft,
            'sender_principal_id' => $senderPrincipal->id,
        ]);

        Post::factory()->for($topicsByName['Research'])->create([
            'body' => "Competitor workflow notes\n\nTrack workflow patterns from adjacent tools and notable gaps.",
            'status' => PostStatus::Draft,
            'sender_principal_id' => $senderPrincipal->id,
        ]);
    }
}
