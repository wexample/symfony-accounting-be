<?php

namespace Wexample\SymfonyAccountingBe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyAccountingBe\Helper\BeIdentityHelper;
use Wexample\SymfonyAccountingBe\Helper\StructuredCommunicationHelper;

class BeHelperTest extends TestCase
{
    public function testEnterpriseNumber(): void
    {
        $this->assertTrue(BeIdentityHelper::isValidEnterpriseNumber('0123.456.749'));
        $this->assertFalse(BeIdentityHelper::isValidEnterpriseNumber('0123.456.748'));
        $this->assertFalse(BeIdentityHelper::isValidEnterpriseNumber('2123.456.749'));
        // Nine-digit numbers from before 2007.
        $this->assertTrue(BeIdentityHelper::isValidEnterpriseNumber('123456749'));
        $this->assertSame('0123.456.749', BeIdentityHelper::format('BE 0123456749'));
        $this->assertTrue(BeIdentityHelper::isValidVatNumber('BE 0123.456.749'));
        $this->assertSame('BE0123456749', BeIdentityHelper::toVatNumber('0123.456.749'));
    }

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
