<?php

/**
 * Utopia PHP Framework
 *
 * @package Framework
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/framework
 * @author Appwrite Team <team@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    protected Domain $domain;

    public function setUp(): void
    {
        $this->domain = new Domain();
    }

    public function testIsValid()
    {
        // Assertions
        $this->assertSame(true, $this->domain->isValid('example.com'));
        $this->assertSame(true, $this->domain->isValid('subdomain.example.com'));
        $this->assertSame(true, $this->domain->isValid('subdomain.example-app.com'));
        $this->assertSame(false, $this->domain->isValid('subdomain.example_app.com'));
        $this->assertSame(true, $this->domain->isValid('subdomain-new.example.com'));
        $this->assertSame(false, $this->domain->isValid('subdomain_new.example.com'));
        $this->assertSame(true, $this->domain->isValid('localhost'));
        $this->assertSame(true, $this->domain->isValid('example.io'));
        $this->assertSame(true, $this->domain->isValid('example.org'));
        $this->assertSame(true, $this->domain->isValid('example.org'));
        $this->assertSame(false, $this->domain->isValid(false));
        $this->assertSame(false, $this->domain->isValid('api.appwrite.io.'));
        $this->assertSame(false, $this->domain->isValid('.api.appwrite.io'));
        $this->assertSame(false, $this->domain->isValid('.api.appwrite.io'));
        $this->assertSame(false, $this->domain->isValid('api..appwrite.io'));
        $this->assertSame(false, $this->domain->isValid('api-.appwrite.io'));
        $this->assertSame(false, $this->domain->isValid('api.-appwrite.io'));
        $this->assertSame(false, $this->domain->isValid('app write.io'));
        $this->assertSame(false, $this->domain->isValid(' appwrite.io'));
        $this->assertSame(false, $this->domain->isValid('appwrite.io '));
        $this->assertSame(false, $this->domain->isValid('-appwrite.io'));
        $this->assertSame(false, $this->domain->isValid('appwrite.io-'));
        $this->assertSame(false, $this->domain->isValid('.'));
        $this->assertSame(false, $this->domain->isValid('..'));
        $this->assertSame(false, $this->domain->isValid(''));
        $this->assertSame(false, $this->domain->isValid(['string', 'string']));
        $this->assertSame(false, $this->domain->isValid(1));
        $this->assertSame(false, $this->domain->isValid(1.2));
    }

    /**
     * Test domain validation with hostnames flag set to false (permissive mode)
     */
    public function testDomainValidationWithHostnamesFalse()
    {
        // Create validator with hostnames=false for permissive validation
        $permissiveValidator = new Domain([], false);

        $this->assertSame(true, $permissiveValidator->isValid('xn--e1afmkfd.xn--p1ai')); // пример.рф in punycode
        $this->assertSame(true, $permissiveValidator->isValid('xn--fsq.com')); // 中.com in punycode
        $this->assertSame(true, $permissiveValidator->isValid('123.com'));
        $this->assertSame(true, $permissiveValidator->isValid('test123.example.com'));
        $this->assertSame(true, $permissiveValidator->isValid('localhost'));
        $this->assertSame(true, $permissiveValidator->isValid('intranet'));
        $this->assertSame(true, $permissiveValidator->isValid('subdomain_new.example.com'));
        $this->assertSame(true, $permissiveValidator->isValid('subdomain.example_app.com'));
        $longLabel = str_repeat('a', 63);
        $this->assertSame(true, $permissiveValidator->isValid($longLabel . '.com'));
        $this->assertSame(true, $permissiveValidator->isValid('a.b.c.d.example.com'));
        $this->assertSame(true, $permissiveValidator->isValid('sub1.sub2.sub3.example.org'));
        $this->assertSame(true, $permissiveValidator->isValid('api-.appwrite.io')); // Dash at end of label
        $this->assertSame(true, $permissiveValidator->isValid('api.-appwrite.io')); // Dash at start of label
        $this->assertSame(true, $permissiveValidator->isValid('app write.io')); // Space in domain
        $this->assertSame(true, $permissiveValidator->isValid(' appwrite.io')); // Leading space
        $this->assertSame(true, $permissiveValidator->isValid('appwrite.io ')); // Trailing space
        $this->assertSame(true, $permissiveValidator->isValid('-appwrite.io')); // Leading dash
    }

    /**
     * Test domains that are invalid even with hostnames=false
     */
    public function testInvalidDomainsWithHostnamesFalse()
    {
        // Create validator with hostnames=false for permissive validation
        $permissiveValidator = new Domain([], false);

        // These should still be invalid even in permissive mode
        $this->assertSame(false, $permissiveValidator->isValid('example..com')); // Double dot
        $this->assertSame(false, $permissiveValidator->isValid('.example.com')); // Leading dot
        $this->assertSame(false, $permissiveValidator->isValid('example.com.')); // Trailing dot (caught by Domain validator)
        $this->assertSame(false, $permissiveValidator->isValid('appwrite.io-')); // Trailing dash (caught by Domain validator)

        // Test label too long (more than 63 characters)
        $tooLongLabel = str_repeat('a', 64);
        $this->assertSame(false, $permissiveValidator->isValid($tooLongLabel . '.com'));

        // Test total domain length too long (more than 253 characters)
        $longDomain = str_repeat('a', 50) . '.' . str_repeat('b', 50) . '.' .
                      str_repeat('c', 50) . '.' . str_repeat('d', 50) . '.' .
                      str_repeat('e', 50) . '.com';
        $this->assertSame(false, $permissiveValidator->isValid($longDomain));

        // Note: These are actually allowed by FILTER_VALIDATE_DOMAIN without FILTER_FLAG_HOSTNAME
        // but might be unexpected:
        $this->assertSame(true, $permissiveValidator->isValid('exam ple.com')); // Space in domain
        $this->assertSame(true, $permissiveValidator->isValid('example@.com')); // @ character
        $this->assertSame(true, $permissiveValidator->isValid('example#.com')); // # character
        $this->assertSame(true, $permissiveValidator->isValid('http://example.com')); // Protocol
        $this->assertSame(true, $permissiveValidator->isValid('example.com:8080')); // Port
        $this->assertSame(true, $permissiveValidator->isValid('example.com/path')); // Path
    }

    /**
     * Test allowEmpty parameter
     */
    public function testAllowEmpty()
    {
        // By default, empty string is invalid
        $this->assertSame(false, $this->domain->isValid(''));

        // With allowEmpty=true, empty string is valid
        $domainAllowEmpty = new Domain([], true, true);
        $this->assertSame(true, $domainAllowEmpty->isValid(''));

        // null is still invalid even with allowEmpty=true
        $this->assertSame(false, $domainAllowEmpty->isValid(null));

        // Valid domains still pass with allowEmpty=true
        $this->assertSame(true, $domainAllowEmpty->isValid('example.com'));
        $this->assertSame(true, $domainAllowEmpty->isValid('subdomain.example.com'));

        // Invalid domains still fail with allowEmpty=true
        $this->assertSame(false, $domainAllowEmpty->isValid('invalid..domain'));
        $this->assertSame(false, $domainAllowEmpty->isValid(1));
    }

    public function testRestrictions()
    {
        $validator = new Domain([
            Domain::createRestriction('appwrite.network', 3, ['preview-', 'branch-']),
            Domain::createRestriction('fra.appwrite.run', 4),
        ]);

        $this->assertSame(true, $validator->isValid('google.com'));
        $this->assertSame(true, $validator->isValid('stage.google.com'));
        $this->assertSame(true, $validator->isValid('shard4.stage.google.com'));

        $this->assertSame(false, $validator->isValid('appwrite.network'));
        $this->assertSame(false, $validator->isValid('preview-a.appwrite.network'));
        $this->assertSame(false, $validator->isValid('branch-a.appwrite.network'));
        $this->assertSame(true, $validator->isValid('google.appwrite.network'));
        $this->assertSame(false, $validator->isValid('stage.google.appwrite.network'));
        $this->assertSame(false, $validator->isValid('shard4.stage.google.appwrite.network'));

        $this->assertSame(false, $validator->isValid('fra.appwrite.run'));
        $this->assertSame(true, $validator->isValid('appwrite.run'));
        $this->assertSame(true, $validator->isValid('google.fra.appwrite.run'));
        $this->assertSame(false, $validator->isValid('shard4.google.fra.appwrite.run'));
        $this->assertSame(true, $validator->isValid('branch-google.fra.appwrite.run'));
        $this->assertSame(true, $validator->isValid('preview-google.fra.appwrite.run'));
    }

    /**
     * Test the hostnames parameter functionality
     */
    public function testHostnamesParameter()
    {
        // Test with hostnames=true (default, strict mode with FILTER_FLAG_HOSTNAME)
        $strictValidator = new Domain([], true);
        $this->assertSame(false, $strictValidator->isValid('subdomain_new.example.com'));
        $this->assertSame(false, $strictValidator->isValid('subdomain.example_app.com'));
        $this->assertSame(false, $strictValidator->isValid('sub_domain.example.com'));
        $this->assertSame(false, $strictValidator->isValid('app write.io'));
        $this->assertSame(false, $strictValidator->isValid('api-.appwrite.io'));
        $this->assertSame(false, $strictValidator->isValid('api.-appwrite.io'));

        // Test with hostnames=false (permissive mode without FILTER_FLAG_HOSTNAME)
        $permissiveValidator = new Domain([], false);
        $this->assertSame(true, $permissiveValidator->isValid('subdomain_new.example.com'));
        $this->assertSame(true, $permissiveValidator->isValid('subdomain.example_app.com'));
        $this->assertSame(true, $permissiveValidator->isValid('sub_domain.example.com'));
        $this->assertSame(true, $permissiveValidator->isValid('app write.io'));
        $this->assertSame(true, $permissiveValidator->isValid('api-.appwrite.io'));
        $this->assertSame(true, $permissiveValidator->isValid('api.-appwrite.io'));

        // Domains without underscores should be valid in both modes
        $this->assertSame(true, $strictValidator->isValid('subdomain.example.com'));
        $this->assertSame(true, $strictValidator->isValid('subdomain-new.example.com'));
        $this->assertSame(true, $permissiveValidator->isValid('subdomain.example.com'));
        $this->assertSame(true, $permissiveValidator->isValid('subdomain-new.example.com'));
    }
}
