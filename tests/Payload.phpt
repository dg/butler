<?php declare(strict_types=1);

use Butler\Payload;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('header names are case-insensitive', function () {
	$payload = new Payload(headers: ['x-hub-signature-256' => 'sha256=abc']);
	Assert::same('sha256=abc', $payload->getHeader('X-Hub-Signature-256'));
	Assert::null($payload->getHeader('X-Missing'));
});


test('empty body decodes as null JSON', function () {
	Assert::null((new Payload)->decodeJson());
});


test('JSON body decodes to objects', function () {
	$data = new Payload('{"ref":"refs/heads/master","commits":[1,2]}')->decodeJson();
	Assert::equal((object) ['ref' => 'refs/heads/master', 'commits' => [1, 2]], $data);
});


test('invalid JSON body throws', function () {
	Assert::exception(
		fn() => new Payload('{"ref":')->decodeJson(),
		JsonException::class,
		'Syntax error%a?%', // PHP 8.6 adds the location
	);
});


test('form body decodes to an array', function () {
	Assert::same(
		['txn_id' => 'A1', 'payload' => '{"a":1}'],
		new Payload('txn_id=A1&payload=%7B%22a%22%3A1%7D')->decodeForm(),
	);
});


test('fromGlobals() takes headers from $_SERVER and only string query values', function () {
	$_SERVER['HTTP_X_GITHUB_EVENT'] = 'release';
	$_SERVER['CONTENT_TYPE'] = 'application/json';
	$_SERVER['SERVER_NAME'] = 'nette.org';
	$_GET = ['release' => '', 'token' => ['x'], 7 => 'seven'];

	$payload = Payload::fromGlobals();
	Assert::same('release', $payload->getHeader('X-GitHub-Event'));
	Assert::same('application/json', $payload->getHeader('Content-Type'));
	Assert::null($payload->getHeader('Server-Name'));
	Assert::same(['release' => '', '7' => 'seven'], $payload->query);
	Assert::same('', $payload->body);
});
