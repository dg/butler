<?php declare(strict_types=1);

use Butler\Payload;
use Butler\Verifiers\{AnyOf, BearerToken, GitHubSignature, None};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('GitHub signature is checked over the exact body', function () {
	$verifier = new GitHubSignature('secret');
	$signature = 'sha256=' . hash_hmac('sha256', '{"a":1}', 'secret');
	Assert::true($verifier->verify(new Payload('{"a":1}', ['x-hub-signature-256' => $signature])));
	Assert::false($verifier->verify(new Payload('{"a":2}', ['x-hub-signature-256' => $signature])));
	Assert::false($verifier->verify(new Payload('{"a":1}')));
});


test('a bearer token is accepted from the header, from the query only when allowed', function () {
	$header = new Payload(headers: ['authorization' => 'Bearer abc']);
	$query = new Payload(query: ['token' => 'abc']);

	Assert::true(new BearerToken('abc')->verify($header));
	Assert::false(new BearerToken('abc')->verify($query));
	Assert::true(new BearerToken('abc', allowQuery: true)->verify($query));
	Assert::false(new BearerToken('abc', allowQuery: true)->verify(new Payload(query: ['token' => 'abd'])));
	Assert::false(new BearerToken('abc')->verify(new Payload(headers: ['authorization' => 'Bearer abcd'])));
});


test('an empty secret is refused', function () {
	Assert::exception(
		fn() => new GitHubSignature(''),
		InvalidArgumentException::class,
		'The secret of a GitHub webhook must not be empty.',
	);
	Assert::exception(fn() => new BearerToken(''), InvalidArgumentException::class, 'The token must not be empty.');
});


test('AnyOf passes when one of the verifiers passes', function () {
	$verifier = new AnyOf(new GitHubSignature('secret'), new BearerToken('abc', allowQuery: true));
	Assert::true($verifier->verify(new Payload(query: ['token' => 'abc'])));
	Assert::false($verifier->verify(new Payload));
});


test('None passes everything', function () {
	Assert::true(new None()->verify(new Payload));
});
