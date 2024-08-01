<?php

declare(strict_types=1);

namespace AppTests\Codeception\Support\Helper;

trait DemoHelper
{
    /**
     * @Given I write a test
     */
    public function iWriteATest(): bool
    {
        return true;
    }

    /**
     * @When I run it
     */
    public function iRunIt(): bool
    {
        return true;
    }

    /**
     * @Then it should pass
     */
    public function itShouldPass(): bool
    {
        return true;
    }
}
