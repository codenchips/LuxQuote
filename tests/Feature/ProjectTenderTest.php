<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_can_store_salesforce_account_tenders(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();

        $tender = ProjectTender::create([
            'project_id' => $project->id,
            'salesforce_account_id' => '001000000000001AAA',
            'account_name' => 'Example Contractor',
            'account_payload' => [
                'Id' => '001000000000001AAA',
                'Name' => 'Example Contractor',
            ],
            'created_by_id' => $user->id,
        ]);

        $this->assertTrue($project->tenders()->whereKey($tender)->exists());
        $this->assertSame('Example Contractor', $tender->fresh()->account_name);
        $this->assertSame('001000000000001AAA', $tender->fresh()->account_payload['Id']);
    }
}
