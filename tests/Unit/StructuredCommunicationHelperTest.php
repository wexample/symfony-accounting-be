<?php

namespace Wexample\SymfonyAccountingBe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyAccountingBe\Helper\StructuredCommunicationHelper;

class StructuredCommunicationHelperTest extends TestCase
{
    public function testStructuredCommunication(): void
    {
        $communication = StructuredCommunicationHelper::generate('2026000012');
        $this->assertMatchesRegularExpression('/^\+\+\+\d{3}\/\d{4}\/\d{5}\+\+\+$/', $communication);
        $this->assertTrue(StructuredCommunicationHelper::isValid($communication));
        // 2026000012 mod 97 = 6.
        $this->assertSame('+++202/6000/01206+++', $communication);
        // A multiple of 97 gets the check 97.
        $this->assertSame('+++000/0000/09797+++', StructuredCommunicationHelper::generate(97));

        $this->assertSame(
            ['+++202/6000/01206+++'],
            StructuredCommunicationHelper::extract('VIREMENT CLIENT A ***202/6000/01206*** MERCI')
        );
        $this->assertSame(['+++202/6000/01206+++'], StructuredCommunicationHelper::extract('Communication 202600001206'));
        $this->assertSame([], StructuredCommunicationHelper::extract('+++202/6000/01207+++'));
    }
}
