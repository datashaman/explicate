<?php

namespace Database\Seeders;

use App\Enums\MessageStatus;
use App\Enums\Provider;
use App\Enums\ReasoningEffort;
use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Message;
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
                'provider' => Provider::Google,
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

        $topicsByName['Design']->agents()->attach([
            $agents[0]->id => ['agent_version_id' => $agents[0]->latestVersion->id],
            $agents[3]->id => ['agent_version_id' => $agents[3]->latestVersion->id],
        ]);

        $topicsByName['Engineering']->agents()->attach([
            $agents[1]->id => ['agent_version_id' => $agents[1]->latestVersion->id],
            $agents[3]->id => ['agent_version_id' => $agents[3]->latestVersion->id],
        ]);

        $topicsByName['Marketing']->agents()->attach([
            $agents[0]->id => ['agent_version_id' => $agents[0]->latestVersion->id],
            $agents[2]->id => ['agent_version_id' => $agents[2]->latestVersion->id],
        ]);

        $topicsByName['Research']->agents()->attach([
            $agents[1]->id => ['agent_version_id' => $agents[1]->latestVersion->id],
        ]);

        $designDraft = Message::factory()->for($topicsByName['Design'])->create([
            'title' => 'Homepage hero directions',
            'slug' => 'homepage-hero-directions',
            'body' => "Explore three tonal directions for the homepage hero.\n\n1. Product-led\n2. Proof-led\n3. Narrative-led",
            'status' => MessageStatus::Draft,
        ]);

        Attachment::factory()->count(2)->for($designDraft)->create();

        Message::factory()->for($topicsByName['Design'])->create([
            'title' => 'Brand voice notes',
            'slug' => 'brand-voice-notes',
            'body' => 'Capture phrases to avoid and preferred tone examples.',
            'status' => MessageStatus::Published,
        ]);

        Message::factory()->for($topicsByName['Engineering'])->create([
            'title' => 'Agent orchestration outline',
            'slug' => 'agent-orchestration-outline',
            'body' => "Document the message lifecycle and queue boundaries.\n\nInclude failure handling and retry policy.",
            'status' => MessageStatus::Draft,
        ]);

        Message::factory()->for($topicsByName['Engineering'])->create([
            'title' => 'Model fallback strategy',
            'slug' => 'model-fallback-strategy',
            'body' => 'Compare provider fallback order and expected quality tradeoffs.',
            'status' => MessageStatus::Archived,
        ]);

        Message::factory()->for($topicsByName['Marketing'])->create([
            'title' => 'Q3 campaign angles',
            'slug' => 'q3-campaign-angles',
            'body' => 'List campaign themes tied to the strongest product outcomes.',
            'status' => MessageStatus::Published,
        ]);

        Message::factory()->for($topicsByName['Marketing'])->create([
            'title' => 'Landing page test ideas',
            'slug' => 'landing-page-test-ideas',
            'body' => 'Propose headline, CTA, and proof-module experiments.',
            'status' => MessageStatus::Draft,
        ]);

        Message::factory()->for($topicsByName['Research'])->create([
            'title' => 'Competitor workflow notes',
            'slug' => 'competitor-workflow-notes',
            'body' => 'Track workflow patterns from adjacent tools and notable gaps.',
            'status' => MessageStatus::Draft,
        ]);
    }
}
