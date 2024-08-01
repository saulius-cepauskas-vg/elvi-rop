<?php

declare(strict_types=1);

namespace AppTests\Codeception\Api;

use AppTests\Codeception\Support\ApiTester;

class DemoCest
{
    public function passDemoTest(ApiTester $I): void
    {
        $I->amGoingTo("Check if API Docs is accessible");
        $I->sendGET('/docs');
        $I->seeResponseCodeIsSuccessful();
    }
}
