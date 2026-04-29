<?php

namespace Tests\Unit\Policies;

use App\Models\BookList;
use App\Models\User;
use App\Policies\BookListPolicy;
use PHPUnit\Framework\TestCase;

class BookListPolicyTest extends TestCase
{
    private BookListPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new BookListPolicy;
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['user_id' => $id]);
    }

    private function list(int $ownerId): BookList
    {
        return (new BookList)->forceFill(['user_id' => $ownerId]);
    }

    public function test_view_any_is_always_allowed(): void
    {
        $this->assertTrue($this->policy->viewAny($this->user(1)));
    }

    public function test_create_is_always_allowed(): void
    {
        $this->assertTrue($this->policy->create($this->user(1)));
    }

    public function test_owner_can_view_update_and_delete_own_list(): void
    {
        $owner = $this->user(7);
        $list = $this->list(7);

        $this->assertTrue($this->policy->view($owner, $list));
        $this->assertTrue($this->policy->update($owner, $list));
        $this->assertTrue($this->policy->delete($owner, $list));
    }

    public function test_non_owner_cannot_view_update_or_delete(): void
    {
        $intruder = $this->user(2);
        $list = $this->list(7);

        $this->assertFalse($this->policy->view($intruder, $list));
        $this->assertFalse($this->policy->update($intruder, $list));
        $this->assertFalse($this->policy->delete($intruder, $list));
    }

    public function test_ownership_check_is_strict_and_does_not_match_loose_types(): void
    {
        $owner = $this->user(7);
        $list = (new BookList)->forceFill(['user_id' => '7']);

        $this->assertFalse(
            $this->policy->view($owner, $list),
            'policy uses === so int 7 must not equal string "7" — guards against accidental loosening'
        );
    }
}
