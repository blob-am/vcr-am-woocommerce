<?php

declare(strict_types=1);

use BlobSolutions\WooCommerceVcrAm\Net\SafeUrlValidator;

beforeEach(function (): void {
    $this->validator = new SafeUrlValidator();
});

it('accepts a clean production HTTPS URL', function (): void {
    expect($this->validator->reject('https://vcr.am/api/v1'))->toBeNull();
});

it('accepts a clean HTTP URL on default ports', function (): void {
    expect($this->validator->reject('http://example.com:80/api'))->toBeNull();
});

it('accepts an URL on a high (>=1024) custom port', function (): void {
    expect($this->validator->reject('https://staging.vcr.am:8443/api/v1'))->toBeNull();
});

it('rejects empty input', function (): void {
    expect($this->validator->reject(''))->not->toBeNull();
});

it('rejects whitespace-only input', function (): void {
    expect($this->validator->reject('   '))->not->toBeNull();
});

it('rejects file:// scheme', function (): void {
    // file:// has empty host, fails the missing-host check first; either
    // rejection is fine, what matters is "not null" (=> rejected).
    expect($this->validator->reject('file:///etc/passwd'))->not->toBeNull();
});

it('rejects ftp:// scheme', function (): void {
    expect($this->validator->reject('ftp://example.com/api'))
        ->toContain('not allowed');
});

it('rejects javascript: scheme', function (): void {
    expect($this->validator->reject('javascript:alert(1)'))
        ->not->toBeNull();
});

it('rejects malformed URLs', function (): void {
    expect($this->validator->reject('not-a-url'))->not->toBeNull();
});

it('rejects loopback IPv4 (127.0.0.1)', function (): void {
    expect($this->validator->reject('http://127.0.0.1/api'))
        ->toContain('private / loopback');
});

it('rejects loopback IPv6 (::1)', function (): void {
    expect($this->validator->reject('http://[::1]/api'))
        ->toContain('private / loopback');
});

it('rejects RFC1918 10.x address', function (): void {
    expect($this->validator->reject('http://10.0.0.5/api'))
        ->toContain('private / loopback');
});

it('rejects RFC1918 192.168.x address', function (): void {
    expect($this->validator->reject('http://192.168.1.1/api'))
        ->toContain('private / loopback');
});

it('rejects RFC1918 172.16-31.x address', function (): void {
    expect($this->validator->reject('http://172.16.0.1/api'))
        ->toContain('private / loopback');
});

it('rejects link-local AWS metadata IP (169.254.169.254)', function (): void {
    // The classic SSRF-via-cloud-metadata vector. Must be rejected with
    // extreme prejudice — leaking the API key here would expose IAM
    // credentials.
    expect($this->validator->reject('http://169.254.169.254/latest/meta-data/'))
        ->toContain('private / loopback');
});

it('rejects localhost hostname', function (): void {
    expect($this->validator->reject('http://localhost:9000/api'))
        ->toContain('local / internal');
});

it('rejects .local mDNS hostnames', function (): void {
    expect($this->validator->reject('http://my-mac.local/api'))
        ->toContain('local / internal');
});

it('rejects .internal hostnames (cloud private DNS)', function (): void {
    expect($this->validator->reject('http://gateway.internal/api'))
        ->toContain('local / internal');
});

it('rejects .lan hostnames', function (): void {
    expect($this->validator->reject('http://router.lan/api'))
        ->toContain('local / internal');
});

it('rejects sub-1024 ports other than 80/443 (e.g. SSH 22)', function (): void {
    expect($this->validator->reject('https://example.com:22/'))
        ->toContain('privileged range');
});

it('accepts port 80 explicitly', function (): void {
    expect($this->validator->reject('http://api.example.com:80/'))->toBeNull();
});

it('accepts port 443 explicitly', function (): void {
    expect($this->validator->reject('https://api.example.com:443/'))->toBeNull();
});
