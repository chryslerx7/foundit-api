<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bpsu_email_domain_assigns_student_role()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'BPSU Student',
            'student_id' => '2026-0001',
            'email' => 'student@bpsu.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('user.role', 'student');
    }

    public function test_uppercase_bpsu_email_domain_assigns_student_role_case_insensitively()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'BPSU Student Upper',
            'student_id' => '2026-0002',
            'email' => 'STUDENT@BPSU.EDU.PH',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('user.role', 'student');
    }

    public function test_gmail_email_domain_assigns_guest_role()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Gmail User',
            'student_id' => '2026-0003',
            'email' => 'person@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('user.role', 'guest');
    }

    public function test_other_email_domain_assigns_guest_role()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Yahoo User',
            'student_id' => '2026-0004',
            'email' => 'person@yahoo.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('user.role', 'guest');
    }

    public function test_deceptive_bpsu_subdomain_assigns_guest_role()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Fake BPSU User',
            'student_id' => '2026-0005',
            'email' => 'person@fakebpsu.edu.ph',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('user.role', 'guest');
    }

    public function test_extended_bpsu_domain_assigns_guest_role()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Example BPSU User',
            'student_id' => '2026-0006',
            'email' => 'person@bpsu.edu.ph.example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('user.role', 'guest');
    }

    public function test_guest_can_perform_normal_user_capabilities()
    {
        $user = User::factory()->create([
            'role' => 'guest',
            'student_id' => '2026-9999',
            'email' => 'guest@gmail.com'
        ]);

        $this->actingAs($user);

        // Guest can create item
        $itemResponse = $this->postJson('/api/items', [
            'item_name' => 'Lost Wallet',
            'category' => 'Electronics',
            'description' => 'Black leather wallet',
            'location' => 'Library',
            'date' => '2026-10-07',
            'type' => 'LOST',
            'contact' => '09123456789'
        ]);

        $itemResponse->assertStatus(201);
        $itemId = $itemResponse->json('item.id');

        // Guest can view items
        $this->getJson('/api/items')->assertStatus(200);

        // Guest can resolve own item
        $this->postJson("/api/items/{$itemId}/resolve")->assertStatus(200);
    }
}
